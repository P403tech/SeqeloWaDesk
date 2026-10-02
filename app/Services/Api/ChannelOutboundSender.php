<?php

namespace App\Services\Api;

use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Services\InboxDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Send a single OUTBOUND message on a NON-WhatsApp channel (Facebook Messenger,
 * Instagram DM, Telegram) from the customer REST API (/api/v1/messages).
 *
 * The heavy lifting already exists: InboxDispatcher::send() routes by the
 * conversation's channel to the per-channel client (FacebookPageClient,
 * InstagramService, TelegramClient), applies the platform halt + monthly-message
 * plan limit, and threads the bubble into the Team Inbox. This class only
 *   1. resolves which connection (page / account / bot) to send from,
 *   2. builds the channel's raw_jid so the thread matches the inbound one,
 *   3. finds-or-creates that conversation + an outbound InboxMessage row,
 *   4. hands it to InboxDispatcher and normalises the result.
 *
 * PLATFORM REALITY: Facebook + Instagram only allow a business-initiated DM
 * inside the 24h customer-service window (the user must have messaged first);
 * Telegram requires the user to have started the bot. So a send to a recipient
 * with no prior inbound will be REJECTED by the platform — we surface that
 * reason verbatim rather than pretending it queued.
 */
class ChannelOutboundSender
{
    public const CHANNELS = ['facebook', 'instagram', 'telegram'];

    /**
     * @param  int          $workspaceId
     * @param  string       $channel       facebook | instagram | telegram
     * @param  string       $to            PSID (facebook) | IGSID (instagram) | chat_id (telegram)
     * @param  string|null  $text          message body / caption
     * @param  string|null  $mediaPath     local chat-media path OR a public http(s) url
     * @param  string|null  $mediaType     image | video | audio | document
     * @param  string|null  $connectionId  optional: pick a specific page/account/bot
     * @return array{ok:bool, status:string, message_id:int|null, provider_id:string|null, error:string|null}
     */
    public function send(
        int $workspaceId,
        string $channel,
        string $to,
        ?string $text = null,
        ?string $mediaPath = null,
        ?string $mediaType = null,
        ?string $connectionId = null
    ): array {
        $channel = strtolower(trim($channel));
        $to      = trim($to);
        $text    = (string) ($text ?? '');

        if (! in_array($channel, self::CHANNELS, true)) {
            return $this->err('unsupported_channel', "Channel '{$channel}' is not supported for send.");
        }
        if ($to === '') {
            return $this->err('recipient_required', 'A recipient id (`to`) is required.');
        }
        if ($text === '' && $mediaPath === null) {
            return $this->err('empty_message', 'Provide `text` or media to send.');
        }

        // 1) Resolve the connection + build the thread key (raw_jid) for this channel.
        try {
            [$rawJid, $title] = $this->resolveRawJid($workspaceId, $channel, $to, $connectionId);
        } catch (\RuntimeException $e) {
            return $this->err('channel_not_connected', $e->getMessage());
        }
        if ($rawJid === null) {
            return $this->err('channel_not_connected', ucfirst($channel) . ' is not connected for this workspace.');
        }

        // 2) Find-or-create the conversation. These channels key on raw_jid (not a
        //    phone number), so we match it directly — ConversationResolver is for
        //    the phone-number channels and returns null for fb:/ig: jids.
        $conv = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->where('channel', $channel)
            ->where('raw_jid', $rawJid)
            ->orderBy('id')
            ->first();

        if (! $conv) {
            $conv = Conversation::create([
                'workspace_id'    => $workspaceId,
                'channel'         => $channel,
                'provider'        => $channel,
                'raw_jid'         => $rawJid,
                'title'           => $title ?: $to,
                // 'inbox' (not 'api') so the thread shows in GET /conversations
                // and the dashboard inbox like any other — chatOnly() filters on
                // origin IN (chat,inbox,chatbot). meta.source still records 'api'.
                'origin'          => 'inbox',
                'status'          => 'open',
                'inbox_status'    => 'open',
                'unread_count'    => 0,
                'last_message_at' => now(),
            ]);
        }

        // 3) Outbound bubble. media_path may be a local chat-media path or a public
        //    http(s) url — the per-channel dispatchers handle both.
        $msg = InboxMessage::create([
            'conversation_id' => $conv->id,
            'direction'       => 'out',
            'provider'        => $channel,
            'to_number'       => $to,
            'body'            => $text,
            'media_path'      => $mediaPath,
            'media_type'      => $mediaPath ? ($mediaType ?: 'document') : null,
            'status'          => 'pending',
            'meta'            => ['source' => 'api'],
        ]);

        // 4) Dispatch through the shared router (channel resolved from conv->channel).
        try {
            $result = app(InboxDispatcher::class)->send($msg);
        } catch (\Throwable $e) {
            // e.g. PlanLimitGuard throwing on a monthly-message cap.
            $msg->forceFill(['status' => 'failed', 'failure_reason' => $e->getMessage()])->saveQuietly();
            Log::warning('[API-CHANNEL-SEND] dispatch threw', ['channel' => $channel, 'error' => $e->getMessage()]);
            return $this->err('send_failed', $e->getMessage(), $msg->id);
        }

        $ok = (bool) ($result['ok'] ?? false);
        $msg->forceFill([
            'status'         => $ok ? 'sent' : 'failed',
            'failure_reason' => $ok ? null : ($result['error'] ?? 'send failed'),
            'sent_at'        => $ok ? now() : null,
        ])->saveQuietly();

        $conv->forceFill([
            'last_message_at'  => now(),
            'last_outbound_at' => now(),
            'preview'          => mb_substr($text !== '' ? $text : ('[' . ($mediaType ?: 'media') . ']'), 0, 200),
        ])->save();

        return [
            'ok'          => $ok,
            'status'      => $ok ? 'sent' : 'failed',
            'message_id'  => $msg->id,
            'provider_id' => $result['provider_id'] ?? null,
            'error'       => $ok ? null : ($result['error'] ?? 'send failed'),
        ];
    }

    /**
     * Resolve the sending connection and return [rawJid, threadTitle].
     *
     * @return array{0:?string,1:?string}
     * @throws \RuntimeException with a client-readable reason when nothing is connected.
     */
    private function resolveRawJid(int $workspaceId, string $channel, string $to, ?string $connectionId): array
    {
        switch ($channel) {
            case 'facebook':
                $q = \App\Models\FacebookPage::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'connected');
                if ($connectionId !== null && $connectionId !== '') {
                    $q->where(fn ($w) => $w->where('id', (int) $connectionId)->orWhere('page_id', $connectionId));
                }
                $page = $q->orderBy('id')->first();
                if (! $page) {
                    throw new \RuntimeException('No connected Facebook Page. Connect one in the dashboard first.');
                }
                return ['fb:' . $page->page_id . ':' . $to, $page->name];

            case 'instagram':
                if (! class_exists(\App\Models\InstagramAccount::class)) {
                    throw new \RuntimeException('Instagram is not available on this deployment.');
                }
                $q = \App\Models\InstagramAccount::query()->where('workspace_id', $workspaceId);
                if ($connectionId !== null && $connectionId !== '') {
                    $q->where(fn ($w) => $w->where('id', (int) $connectionId)->orWhere('ig_user_id', $connectionId));
                }
                $acct = $q->orderBy('id')->first();
                if (! $acct) {
                    throw new \RuntimeException('No connected Instagram account. Connect one in the dashboard first.');
                }
                return ['ig:' . $acct->ig_user_id . ':' . $to, $acct->username];

            case 'telegram':
                $q = \App\Models\TelegramBot::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('active', true);
                if ($connectionId !== null && $connectionId !== '') {
                    $q->where('id', (int) $connectionId);
                }
                $bot = $q->orderBy('id')->first();
                if (! $bot) {
                    throw new \RuntimeException('No connected Telegram bot. Connect one in the dashboard first.');
                }
                // forConversation() parses the bot's DB id from tg:<botId>:<chatId>.
                return ['tg:' . $bot->id . ':' . $to, $bot->bot_name ?: $bot->bot_username];
        }

        return [null, null];
    }

    private function err(string $code, string $message, ?int $messageId = null): array
    {
        return [
            'ok'          => false,
            'status'      => 'failed',
            'message_id'  => $messageId,
            'provider_id' => null,
            'error'       => $message,
            'code'        => $code,
        ];
    }
}
