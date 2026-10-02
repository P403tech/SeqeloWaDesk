<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FacebookPage;
use App\Models\TelegramBot;
use App\Models\WaProviderConfig;
use App\Services\Api\ChannelOutboundSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Channels — one read across EVERY messaging channel the workspace has
 * connected (Unofficial/WABA/Twilio WhatsApp, Facebook, Instagram, Telegram),
 * so a developer can discover which channels + connection ids are available to
 * send on via POST /messages. Connect flows stay where they belong: WhatsApp
 * (Unofficial) connects over the API (POST /whatsapp/connect); Facebook,
 * Instagram and Telegram connect in the dashboard (OAuth / bot token).
 */
class ChannelController extends V1Controller
{
    /** GET /api/v1/channels — every connected channel, normalised. */
    public function index(): JsonResponse
    {
        $wsId = $this->workspaceId();
        $out  = [];

        // WhatsApp — all engines live in wa_provider_configs.
        foreach (WaProviderConfig::query()->where('workspace_id', $wsId)
            ->whereIn('provider', ['baileys', 'waba', 'twilio'])
            ->orderByDesc('is_primary')->orderByDesc('id')->get() as $c) {
            $connected = $c->status === WaProviderConfig::STATUS_CONNECTED;
            $out[] = [
                'type'       => $c->provider === 'baileys' ? 'whatsapp_unofficial'
                              : ($c->provider === 'twilio' ? 'whatsapp_twilio' : 'whatsapp'),
                'id'         => (int) $c->id,
                'label'      => $c->display_label ?: ucfirst((string) $c->provider),
                'identifier' => preg_replace('/\D+/', '', (string) $c->phone_number) ?: null,
                'status'     => $connected ? 'connected' : 'disconnected',
            ];
        }

        // Facebook Pages.
        foreach (FacebookPage::query()->where('workspace_id', $wsId)->orderBy('id')->get() as $p) {
            $out[] = [
                'type'       => 'facebook',
                'id'         => (int) $p->id,
                'label'      => $p->name ?: ('Page ' . $p->page_id),
                'identifier' => (string) $p->page_id,
                'status'     => $p->status === 'connected' ? 'connected' : 'disconnected',
            ];
        }

        // Instagram accounts (addon — may not be installed).
        if (class_exists(\App\Models\InstagramAccount::class)) {
            foreach (\App\Models\InstagramAccount::query()->where('workspace_id', $wsId)->orderBy('id')->get() as $a) {
                $out[] = [
                    'type'       => 'instagram',
                    'id'         => (int) $a->id,
                    'label'      => $a->username ?: ('IG ' . $a->ig_user_id),
                    'identifier' => (string) $a->ig_user_id,
                    'status'     => 'connected',
                ];
            }
        }

        // Telegram bots.
        foreach (TelegramBot::query()->where('workspace_id', $wsId)->orderBy('id')->get() as $b) {
            $out[] = [
                'type'       => 'telegram',
                'id'         => (int) $b->id,
                'label'      => $b->bot_name ?: ($b->bot_username ?: ('Bot ' . $b->bot_id)),
                'identifier' => (string) ($b->bot_username ?: $b->bot_id),
                'status'     => $b->active ? 'connected' : 'disconnected',
            ];
        }

        return $this->ok($out, ['count' => count($out)]);
    }

    /**
     * POST /api/v1/channels/broadcast — send ONE message to many recipients on a
     * single channel (Facebook / Instagram / Telegram), the channel equivalent of
     * /broadcasts. Sends are performed inline and each recipient's result is
     * returned, so you see exactly who received it and who didn't.
     *
     * PLATFORM REALITY: Facebook + Instagram only deliver to a recipient inside
     * the 24h window (they must have messaged you first); Telegram needs the user
     * to have started the bot. Recipients outside that window come back `failed`
     * with the platform's reason — nothing is silently dropped.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel'       => ['required', 'string', 'in:facebook,instagram,telegram'],
            'recipients'    => ['required', 'array', 'min:1', 'max:500'],
            'recipients.*'  => ['string', 'max:64'],
            'text'          => ['nullable', 'string', 'max:4096'],
            'media_url'     => ['nullable', 'url', 'max:2048'],
            'media_kind'    => ['nullable', 'in:image,video,document,audio'],
            'connection_id' => ['nullable', 'string', 'max:64'],
        ]);

        $sender = app(ChannelOutboundSender::class);
        $wsId   = $this->workspaceId();
        $results = [];
        $sent = 0; $failed = 0;

        // De-dupe recipients so the same person isn't messaged twice in one call.
        foreach (array_values(array_unique(array_map('strval', $data['recipients']))) as $to) {
            $to = trim($to);
            if ($to === '') { continue; }

            $r = $sender->send(
                workspaceId: $wsId,
                channel: $data['channel'],
                to: $to,
                text: $data['text'] ?? null,
                mediaPath: $data['media_url'] ?? null,
                mediaType: $data['media_kind'] ?? null,
                connectionId: $data['connection_id'] ?? null,
            );

            ($r['ok'] ?? false) ? $sent++ : $failed++;
            $results[] = [
                'to'         => $to,
                'status'     => $r['status'] ?? ($r['ok'] ?? false ? 'sent' : 'failed'),
                'message_id' => $r['message_id'] ?? null,
                'error'      => $r['error'] ?? null,
            ];
        }

        return $this->created([
            'channel' => $data['channel'],
            'total'   => count($results),
            'sent'    => $sent,
            'failed'  => $failed,
            'results' => $results,
        ]);
    }
}
