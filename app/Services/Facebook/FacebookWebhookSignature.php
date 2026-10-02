<?php

namespace App\Services\Facebook;

use App\Models\SystemSetting;

/**
 * Signs and verifies Meta Facebook Page webhook payloads. Verification always
 * uses the unmodified raw request body and constant-time comparison. Reuses the
 * same Meta app secret already configured for WhatsApp unless a Facebook
 * override is set.
 */
final class FacebookWebhookSignature
{
    /** @return array<string, string> label => secret */
    public static function configuredSecrets(): array
    {
        $configured = [
            'fb_app_secret'   => (string) SystemSetting::get('fb_app_secret', ''),
            'waba_app_secret' => (string) SystemSetting::get('waba_app_secret', ''),
        ];

        // Workspaces that connected with their OWN Meta app sign webhooks with
        // THEIR app secret — include those so their inbound DMs/comments verify
        // (else the event is rejected and automation never fires). UNGATED (mirrors
        // WhatsApp): ALWAYS include every stored workspace secret — required for
        // own-app clients to get inbound while the platform app is in App Review.
        try {
            foreach (\App\Models\Workspace::query()->whereNotNull('meta_app_secret')->get() as $ws) {
                $s = trim((string) $ws->meta_app_secret);
                if ($s !== '') $configured['ws_' . $ws->id] = $s;
            }
        } catch (\Throwable $e) { /* never break signature verification */ }

        $out = [];
        $seen = [];
        foreach ($configured as $label => $secret) {
            $secret = trim($secret);
            if ($secret === '') {
                continue;
            }
            $fingerprint = hash('sha256', $secret);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $out[$label] = $secret;
        }

        return $out;
    }

    public static function make(string $rawBody, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $secret);
    }

    /**
     * Return the label of the secret that matched, or null when the signature
     * is missing/invalid.
     *
     * @param  array<string, string>  $secrets
     */
    public static function verifyAgainst(string $rawBody, string $given, array $secrets): ?string
    {
        if (! str_starts_with($given, 'sha256=')) {
            return null;
        }
        foreach ($secrets as $label => $secret) {
            if ($secret !== '' && hash_equals(self::make($rawBody, $secret), $given)) {
                return (string) $label;
            }
        }

        return null;
    }

    public static function verify(string $rawBody, string $given): ?string
    {
        return self::verifyAgainst($rawBody, $given, self::configuredSecrets());
    }
}
