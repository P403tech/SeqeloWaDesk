<?php

namespace App\Support;

use App\Models\User;

/**
 * Mobile-number helpers for registration uniqueness.
 *
 * A phone number can be typed many ways (+91 98765 43210, 09876543210,
 * 9876543210) and the country code lives in its own column, so a plain
 * `unique:users,mobile` rule would miss real duplicates. We compare on a
 * digits-only canonical of country_code + mobile (and of the bare mobile) so
 * the same number can't register more than one account regardless of format.
 */
class MobileNumber
{
    /** Digits-only form of a raw phone string ("+91 98-765" -> "9198765"). */
    public static function digits(?string $raw): string
    {
        return preg_replace('/\D+/', '', (string) $raw) ?? '';
    }

    /**
     * Is this mobile already registered to a user?
     *
     * Compares the digits-only canonical of the incoming country_code+mobile
     * (and the bare mobile) against every existing user's equivalently-computed
     * value. Chunked so memory stays bounded, and normalised in PHP so it works
     * on any MySQL/MariaDB version and regardless of how the number was stored.
     */
    public static function isTaken(?string $countryCode, ?string $mobile, ?int $ignoreUserId = null): bool
    {
        $bare = self::digits($mobile);
        if ($bare === '') {
            return false;   // no mobile given — nothing to enforce
        }
        $full = self::digits($countryCode) . $bare;

        $found = false;
        User::query()
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '')
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->select('id', 'country_code', 'mobile')
            ->chunkById(1000, function ($rows) use (&$found, $bare, $full) {
                foreach ($rows as $u) {
                    $uBare = self::digits($u->mobile);
                    if ($uBare === '') {
                        continue;
                    }
                    $uFull = self::digits($u->country_code) . $uBare;
                    // Match on either canonical, and cross-match so a number
                    // stored WITH its country code collides with the same number
                    // entered as cc + bare, and vice-versa.
                    if ($uBare === $bare || $uFull === $full || $uFull === $bare || $uBare === $full) {
                        $found = true;
                        return false;   // stop chunking
                    }
                }
            });

        return $found;
    }
}
