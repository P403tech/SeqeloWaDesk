<?php

namespace App\Services\LeadScoring;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Automatically stop/pause when a lead replies or converts."
 *
 * When a lead converts (deal won / payment) — or optionally replies — any
 * automated outreach still running for that contact is halted so the SDR never
 * keeps chasing someone who's already responded or bought. Halts the two
 * existing outreach runners keyed by contact: flow_subscribers (flow runs) and
 * drip_subscribers (email/multi-step cadences). Idempotent and defensive — a
 * missing table or column is skipped, never fatal.
 */
class ConversionStopService
{
    /** Stop because the lead converted (deal won / paid). */
    public static function onConversion(Contact $contact, string $reason = 'converted'): int
    {
        return self::stop($contact, $reason);
    }

    /** Stop because the lead replied inside an active sequence. */
    public static function onReply(Contact $contact, string $reason = 'replied'): int
    {
        return self::stop($contact, $reason);
    }

    /**
     * Halt every active automated sequence for a contact.
     *
     * @return int number of subscriber rows paused
     */
    public static function stop(Contact $contact, string $reason = 'converted'): int
    {
        $contactId = (int) ($contact->id ?? 0);
        if ($contactId <= 0) {
            return 0;
        }

        $paused = 0;
        try {
            foreach (['flow_subscribers', 'drip_subscribers'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status') || ! Schema::hasColumn($table, 'contact_id')) {
                    continue;
                }
                $update = ['status' => 'stopped'];
                if (Schema::hasColumn($table, 'updated_at')) {
                    $update['updated_at'] = now();
                }
                if (Schema::hasColumn($table, 'stopped_reason')) {
                    $update['stopped_reason'] = $reason;
                }
                $paused += DB::table($table)
                    ->where('contact_id', $contactId)
                    ->whereIn('status', ['active', 'pending', 'scheduled'])
                    ->update($update);
            }

            if ($paused > 0) {
                Log::info('[SDR-STOP] outreach halted', [
                    'contact' => $contactId, 'reason' => $reason, 'paused' => $paused,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[SDR-STOP] stop failed: ' . $e->getMessage(), ['contact' => $contactId]);
        }

        return $paused;
    }
}
