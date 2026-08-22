<?php

namespace App\Services\Push;

use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a new inbound WhatsApp message into an FCM push to the operators who
 * are pinned to the number that received it. Best-effort and self-contained:
 * NEVER throws (a push failure must not break the inbound webhook), no-ops when
 * FCM isn't configured, throttles bursts, and prunes dead tokens.
 */
class InboxPushNotifier
{
    public function __construct(private readonly FcmService $fcm) {}

    /**
     * @param int      $workspaceId    conversation's workspace
     * @param int|null $deviceId       the number/device that received the message
     * @param int      $conversationId for the tap deep-link
     * @param string   $title          sender display (name · number)
     * @param string   $body           message preview (or "📎 Image", etc.)
     * @param array    $extra          extra data payload (message_id, sender_jid…)
     */
    public function inbound(int $workspaceId, ?int $deviceId, int $conversationId, string $title, string $body, array $extra = []): void
    {
        try {
            if ($workspaceId <= 0 || !$this->fcm->enabled()) return;

            // Burst guard: at most one push per conversation per 3s.
            $throttleKey = 'fcm_throttle_' . $conversationId;
            if (Cache::get($throttleKey)) return;
            Cache::put($throttleKey, 1, 3);

            // Recipients: tokens for this workspace whose pinned device matches
            // the receiving number (or that subscribed to ALL devices, device_id
            // NULL) — same device scoping as the /inbox delta + campaign preflight.
            $rows = UserDeviceToken::query()
                ->where('workspace_id', $workspaceId)
                ->when($deviceId, fn ($q) => $q->where(function ($w) use ($deviceId) {
                    $w->where('device_id', $deviceId)->orWhereNull('device_id');
                }))
                ->get(['fcm_token']);
            if ($rows->isEmpty()) return;

            // Per-chat unread → drives the notification/badge count (best-effort).
            $unread = (int) (\App\Models\Conversation::whereKey($conversationId)->value('unread_count') ?? 0);

            // Group key shared by EVERY message from this conversation. Android
            // replaces the previous notification carrying the same `tag` (so one
            // chat = one tile showing the latest message, and a single tap clears
            // it — real WhatsApp behaviour, not a stack of one-per-message);
            // iOS `thread-id` collapses them into one expandable stack. Without
            // these, every message was a standalone notification that had to be
            // cleared one at a time.
            $groupTag = 'chat_' . $conversationId;

            $res = $this->fcm->sendToTokens(
                $rows->pluck('fcm_token')->all(),
                ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($body, 0, 180)],
                array_merge([
                    'type'            => 'chat_message',
                    'conversation_id' => (string) $conversationId,
                    'workspace_id'    => (string) $workspaceId,
                    'device_id'       => (string) ($deviceId ?? ''),
                ], $extra),
                // Android: HIGH priority bypasses doze (arrives in seconds, not
                // minutes when idle); `tag` collapses/replaces per conversation.
                ['priority' => 'high', 'notification' => array_filter([
                    'channel_id'         => 'chat_messages',
                    'sound'              => 'default',
                    'tag'                => $groupTag,
                    'notification_count' => $unread > 0 ? $unread : null,
                ], fn ($v) => $v !== null)],
                // iOS: thread-id groups the chat; badge shows the per-chat unread.
                ['payload' => ['aps' => array_filter([
                    'sound'             => 'default',
                    'content-available' => 1,
                    'thread-id'         => $groupTag,
                    'badge'             => $unread > 0 ? $unread : null,
                ], fn ($v) => $v !== null)]]
            );

            // Prune tokens FCM said are dead so we stop pushing to them.
            if (!empty($res['invalid'])) {
                $hashes = array_map(fn ($t) => UserDeviceToken::hashFor((string) $t), $res['invalid']);
                UserDeviceToken::whereIn('token_hash', $hashes)->delete();
            }
            Log::info('[FCM] inbound push', [
                'conv' => $conversationId, 'ws' => $workspaceId, 'device' => $deviceId,
                'sent' => $res['sent'], 'failed' => $res['failed'], 'pruned' => count($res['invalid']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[FCM] inbound push failed: ' . $e->getMessage());
        }
    }
}
