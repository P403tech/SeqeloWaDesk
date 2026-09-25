<?php

namespace App\Support;

/**
 * After Facebook / TikTok / Shopify / Instagram OAuth, send the operator
 * back to the AI agent Knowledge step (channels live there) instead of /devices.
 */
class ChannelSetupReturn
{
    public const KEY = 'channel_setup_return';

    public static function remember(?string $url = null): void
    {
        $raw = trim((string) ($url ?? request()->input('return', request()->query('return', ''))));
        if ($raw === '') {
            return;
        }
        $abs = str_starts_with($raw, 'http') ? $raw : url($raw);
        $base = rtrim((string) url('/'), '/');
        if (! str_starts_with($abs, $base.'/ai-training')) {
            return;
        }
        session([self::KEY => $abs]);
    }

    public static function url(string $fallback): string
    {
        $to = (string) session()->pull(self::KEY, '');

        return $to !== '' ? $to : $fallback;
    }
}
