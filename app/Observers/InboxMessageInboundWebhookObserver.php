<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Services\Inbox\OutboundWebhookDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Fire the developer-facing `conversation.received` webhook for an INBOUND
 * message on any NON-WhatsApp channel (Facebook, Instagram, Telegram, LINE,
 * WeChat, Viber, SMS, email, TikTok). Every channel lands its inbound bubble in
 * `inbox_messages`, so this single observer gives every channel the same inbound
 * webhook WhatsApp already emits — without editing each channel's ingest path.
 *
 * WhatsApp is intentionally skipped here: WaInboundController already fires
 * `conversation.received` for it, so firing again would double-deliver. The
 * event name is identical across channels, so a developer's existing WhatsApp
 * webhook integration receives Facebook / Instagram / Telegram inbound too — the
 * `data.channel` field says which channel it came from.
 */
class InboxMessageInboundWebhookObserver
{
    public function created(InboxMessage $m): void
    {
        if ($m->direction !== 'in') {
            return;
        }

        try {
            $conv = $m->conversation;
            if (! $conv) {
                return;
            }

            $channel = (string) $conv->channel;
            // Only the engine-agnostic (non-WhatsApp) channels — WhatsApp inbound
            // is emitted by WaInboundController to avoid a duplicate delivery.
            if (! in_array($channel, Conversation::ENGINE_AGNOSTIC_CHANNELS, true)) {
                return;
            }

            app(OutboundWebhookDispatcher::class)->fire('conversation.received', $conv, [
                'channel' => $channel,
                'message' => [
                    'id'         => $m->id,
                    'direction'  => 'in',
                    'from'       => $m->from_number,
                    'to'         => $m->to_number,
                    'type'       => $m->media_type ?: 'text',
                    'body'       => $m->body,
                    'media_url'  => $m->media_path
                        ? (preg_match('#^https?://#i', (string) $m->media_path) ? $m->media_path : media_url($m->media_path))
                        : null,
                    'created_at' => optional($m->created_at)->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            // Best-effort — a webhook problem must never break message ingest.
            Log::warning('[INBOUND-WEBHOOK] channel fire failed', ['inbox_id' => $m->id, 'error' => $e->getMessage()]);
        }
    }
}
