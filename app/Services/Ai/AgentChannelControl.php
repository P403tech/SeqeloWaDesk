<?php

namespace App\Services\Ai;

use App\Models\AiAgent;
use App\Models\Conversation;
use App\Models\InboxMessage;

/**
 * Per-channel control: full inbox vs only chosen surfaces (DMs / comments / stories)
 * and optional keyword gate. Full control is the default when a channel is turned on.
 */
class AgentChannelControl
{
    public static function defaults(): array
    {
        $one = fn () => [
            'mode' => 'full',
            'dms' => true,
            'comments' => false,
            'stories' => false,
            'orders' => false,
            'keyword' => false,
            'keyword_text' => '',
        ];

        return [
            'whatsapp' => $one(),
            'facebook' => $one(),
            'instagram' => $one(),
            'tiktok' => $one(),
        ];
    }

    public static function normalize(mixed $raw): array
    {
        $base = self::defaults();
        $in = is_array($raw) ? $raw : [];
        foreach ($base as $ch => $def) {
            $row = is_array($in[$ch] ?? null) ? $in[$ch] : [];
            $mode = (($row['mode'] ?? 'full') === 'specific') ? 'specific' : 'full';
            $base[$ch] = [
                'mode' => $mode,
                'dms' => array_key_exists('dms', $row) ? (bool) $row['dms'] : true,
                'comments' => (bool) ($row['comments'] ?? false),
                'stories' => (bool) ($row['stories'] ?? false),
                'orders' => (bool) ($row['orders'] ?? false),
                'keyword' => (bool) ($row['keyword'] ?? false),
                'keyword_text' => mb_substr(trim((string) ($row['keyword_text'] ?? '')), 0, 80),
            ];
            if ($mode === 'specific' && ! $base[$ch]['dms'] && ! $base[$ch]['comments'] && ! $base[$ch]['stories'] && ! $base[$ch]['orders']) {
                $base[$ch]['dms'] = true;
            }
        }

        return $base;
    }

    public static function inboundKind(Conversation $convo): string
    {
        $meta = is_array($convo->routing_meta) ? $convo->routing_meta : [];
        $kind = strtolower((string) ($meta['thread_kind'] ?? $meta['kind'] ?? ''));
        if (in_array($kind, ['dm', 'comment', 'story'], true)) {
            return $kind;
        }

        $last = InboxMessage::query()
            ->where('conversation_id', $convo->id)
            ->where('direction', 'in')
            ->orderByDesc('id')
            ->value('meta');
        $last = is_array($last) ? $last : [];
        $fb = strtolower((string) data_get($last, 'facebook.kind', data_get($last, 'kind', '')));
        if (in_array($fb, ['dm', 'comment', 'story'], true)) {
            return $fb;
        }
        $ig = strtolower((string) data_get($last, 'ig_trigger', ''));
        if (str_contains($ig, 'story')) {
            return 'story';
        }
        if (str_contains($ig, 'comment') || str_contains($ig, 'mention')) {
            return 'comment';
        }

        $ch = strtolower((string) ($convo->channel ?? ''));
        if ($ch === 'facebook' && (str_contains((string) $convo->raw_jid, ':post:') || str_contains((string) $convo->raw_jid, ':comment:'))) {
            return 'comment';
        }

        return 'dm';
    }

    public static function allows(AiAgent $agent, Conversation $convo, string $text = ''): bool
    {
        $ch = strtolower(trim((string) ($convo->channel ?? '')));
        if ($ch === '' || $ch === 'whatsapp') {
            $ch = 'whatsapp';
        }
        $ctrl = self::normalize($agent->channel_control ?? []);
        $row = $ctrl[$ch] ?? $ctrl['whatsapp'];
        if (($row['mode'] ?? 'full') !== 'specific') {
            return true;
        }

        $kind = self::inboundKind($convo);
        $surfaceOk = match ($kind) {
            'comment' => (bool) $row['comments'],
            'story' => (bool) $row['stories'],
            default => (bool) $row['dms'] || (bool) $row['orders'],
        };
        if (! $surfaceOk) {
            return false;
        }

        if ($kind === 'dm' && ! $row['dms'] && $row['orders']) {
            if (! self::looksLikeOrder($text)) {
                return false;
            }
        }

        if ($row['keyword']) {
            $kw = mb_strtolower(trim((string) $row['keyword_text']));
            if ($kw !== '' && ! str_contains(mb_strtolower($text), $kw)) {
                return false;
            }
        }

        return true;
    }

    private static function looksLikeOrder(string $text): bool
    {
        $t = mb_strtolower($text);

        return (bool) preg_match('/\b(order|track|shipping|delivery|refund|return|sku|price|stock|catalog|#\d{3,})\b/u', $t);
    }
}
