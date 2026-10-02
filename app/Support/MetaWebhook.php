<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * Shared Meta (WhatsApp / Facebook / Instagram) webhook verify token.
 *
 * All three Meta channels use the SAME platform verify token for the GET
 * subscription handshake. WhatsApp already auto-generated it on demand, but
 * Facebook and Instagram only read it and returned 403 when the admin had left
 * it blank — so a client connecting their OWN Meta app could never complete the
 * webhook handshake and inbound never started.
 *
 * This resolves the shared token and AUTO-GENERATES + persists one the first
 * time it is needed, so a fresh install with no admin webhook config still lets
 * a bring-your-own-app client wire inbound. The value the handshake expects and
 * the value shown to the client in settings are therefore always identical.
 */
class MetaWebhook
{
    /** The shared verify token, generating + saving one if none is set yet. */
    public static function verifyToken(): string
    {
        try {
            $tok = (string) SystemSetting::get('waba_webhook_verify_token', '');
            if ($tok === '') {
                $tok = Str::random(40);
                SystemSetting::set(
                    'waba_webhook_verify_token',
                    $tok,
                    'string',
                    'Webhook verify token Meta echoes on subscription (auto-generated; shared by WhatsApp, Facebook and Instagram).'
                );
            }
            return $tok;
        } catch (\Throwable $e) {
            // Never let a settings hiccup break the webhook handshake path.
            return (string) SystemSetting::get('waba_webhook_verify_token', '');
        }
    }
}
