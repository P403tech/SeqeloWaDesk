<?php

namespace App\Services\Waba;

use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Recovers a WABA config's MISSING `waba_id`.
 *
 * Why this exists: template SENDING (TemplateSender) needs only access_token +
 * phone_number_id, but template SYNC/import (TemplateClient) hits
 * /{waba_id}/message_templates and so needs the waba_id. A number connected by
 * an older / partial flow can carry the token + phone_number_id (sends fine) yet
 * lack the waba_id, which makes "Sync from Meta" fail with
 * "WABA config is missing access_token or waba_id" even though outbound works.
 *
 * Meta exposes no phone_number_id → WABA field. But the connect flows also store
 * the owning `business_id`, and from that we CAN list the business's WABAs
 * (owned + client) and match the one whose phone_numbers include our
 * phone_number_id — then backfill meta_json.waba_id so every future WABA op has
 * it. Entirely best-effort: any failure returns null and the caller falls back
 * to its normal "reconnect the number" error.
 */
class WabaIdBackfiller
{
    /**
     * Return the config's waba_id — stored if present, else derived from Meta
     * and persisted onto meta_json. null when it cannot be resolved.
     */
    /**
     * @param  bool  $force  Re-derive even when a waba_id is already stored.
     *                       Used when Meta has REJECTED the stored id, which is
     *                       how a phone_number_id saved in the waba_id slot
     *                       surfaces: sending works (it uses phone_number_id),
     *                       but every template call fails because a phone
     *                       number object has no message_templates edge.
     */
    public static function resolve(WaProviderConfig $cfg, bool $force = false): ?string
    {
        $meta  = is_array($cfg->meta_json) ? $cfg->meta_json : [];
        $creds = $cfg->creds();

        $existing = (string) ($meta['waba_id'] ?? $creds['waba_id'] ?? '');
        if ($existing !== '' && ! $force) return $existing;

        $token   = (string) ($creds['access_token'] ?? '');
        $phoneId = (string) ($meta['phone_number_id'] ?? $creds['phone_number_id'] ?? '');
        $bizId   = (string) ($meta['business_id'] ?? $creds['business_id'] ?? '');
        if ($token === '' || $phoneId === '') {
            Log::warning('[WABA-BACKFILL] cannot resolve — missing inputs', [
                'config_id'       => $cfg->id,
                'has_token'       => $token !== '',
                'phone_number_id' => $phoneId ?: '(empty)',
                'business_id'     => $bizId ?: '(empty)',
                'stored_waba_id'  => $existing ?: '(empty)',
            ]);

            return null;
        }

        Log::info('[WABA-BACKFILL] resolving', [
            'config_id'       => $cfg->id,
            'force'           => $force,
            'stored_waba_id'  => $existing ?: '(empty)',
            'phone_number_id' => $phoneId,
            'business_id'     => $bizId ?: '(empty)',
        ]);

        $gv   = (string) SystemSetting::get('waba_graph_api_version', 'v23.0');
        $base = 'https://graph.facebook.com/' . ltrim($gv, '/');

        // ---- Route 1: the token's own granular scopes ----------------------
        // A WhatsApp system-user token carries, per permission, the list of
        // object ids it was granted against. For whatsapp_business_management
        // those target_ids ARE the WABA ids this token can manage — so the
        // token tells us the answer without needing business_id at all.
        //
        // This replaces an earlier attempt that did
        // GET /{phone_number_id}?fields=whatsapp_business_account.
        // That field does NOT exist on a phone number node; Meta answers
        // "(#100) Tried accessing nonexisting field (whatsapp_business_account)",
        // so it never resolved anything.
        try {
            $dbg = Http::withToken($token)->timeout(12)
                ->get("{$base}/debug_token", ['input_token' => $token]);

            $candidates = [];
            foreach ((array) $dbg->json('data.granular_scopes', []) as $g) {
                if ((string) ($g['scope'] ?? '') === 'whatsapp_business_management') {
                    foreach ((array) ($g['target_ids'] ?? []) as $tid) {
                        $candidates[] = (string) $tid;
                    }
                }
            }
            $candidates = array_values(array_unique(array_filter($candidates)));

            // The decisive line. If this is empty the token was never scoped to
            // a WABA and NOTHING can recover the id — the connection has to be
            // re-made. Logging the raw scope list makes that provable instead
            // of a guess.
            Log::info('[WABA-BACKFILL] token granular_scopes', [
                'config_id'     => $cfg->id,
                'http'          => $dbg->status(),
                'candidates'    => $candidates,
                'all_scopes'    => (array) $dbg->json('data.scopes', []),
                'granular_raw'  => (array) $dbg->json('data.granular_scopes', []),
                'token_app_id'  => (string) $dbg->json('data.app_id', ''),
            ]);

            // Confirm by phone before adopting: a token can be granted several
            // WABAs, and picking the wrong one syncs someone else's templates.
            // With exactly one candidate and no phone to match on, accept it.
            $match = null;
            foreach ($candidates as $cand) {
                if ($cand === $existing) continue;   // the known-bad value

                $pn = Http::withToken($token)->timeout(12)
                    ->get("{$base}/{$cand}/phone_numbers", ['fields' => 'id', 'limit' => 100]);

                if (! $pn->successful()) continue;

                foreach ((array) $pn->json('data', []) as $row) {
                    if ((string) ($row['id'] ?? '') === $phoneId) { $match = $cand; break 2; }
                }
            }
            if (! $match && count($candidates) === 1 && $candidates[0] !== $existing) {
                $match = $candidates[0];
            }

            if ($match) {
                $cfg->meta_json = array_merge($meta, ['waba_id' => $match]);
                $cfg->save();
                Log::info('[WABA-BACKFILL] recovered waba_id from token granular_scopes', [
                    'config_id'       => $cfg->id,
                    'waba_id'         => $match,
                    'phone_number_id' => $phoneId,
                    'replaced'        => $existing !== '' ? $existing : null,
                    'candidates'      => $candidates,
                ]);

                return $match;
            }
        } catch (\Throwable $e) {
            Log::warning('[WABA-BACKFILL] granular_scopes route threw', [
                'config_id' => $cfg->id,
                'err'       => $e->getMessage(),
            ]);
        }

        // ---- Route 2: discover the business from the token ------------------
        // A token can carry whatsapp_business_management with NO target_ids —
        // permissions granted, but not tied to specific WABAs — which is what
        // route 1 above hits. In that case ask the token who it belongs to:
        // /me/businesses lists every business it can act for, and each of those
        // exposes its WABAs. This is what makes recovery possible when
        // business_id was never captured at connect time.
        $bizIds = $bizId !== '' ? [$bizId] : [];

        if (empty($bizIds)) {
            try {
                $me = Http::withToken($token)->timeout(12)
                    ->get("{$base}/me/businesses", ['fields' => 'id,name', 'limit' => 100]);

                foreach ((array) $me->json('data', []) as $row) {
                    if (!empty($row['id'])) $bizIds[] = (string) $row['id'];
                }

                Log::info('[WABA-BACKFILL] discovered businesses from token', [
                    'config_id'  => $cfg->id,
                    'http'       => $me->status(),
                    'businesses' => $bizIds,
                    'error'      => (string) ($me->json('error.message') ?? ''),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[WABA-BACKFILL] /me/businesses threw', [
                    'config_id' => $cfg->id,
                    'err'       => $e->getMessage(),
                ]);
            }
        }

        if (empty($bizIds)) {
            Log::warning('[WABA-BACKFILL] giving up — no business to walk', [
                'config_id' => $cfg->id,
                'note'      => 'token has no WABA target_ids and belongs to no discoverable business; the connection must be re-made',
            ]);

            return null;
        }

        try {
            // 1) Every WABA the business owns or is a client of.
            $wabaIds = [];
            foreach ($bizIds as $bid) {
                foreach (['owned_whatsapp_business_accounts', 'client_whatsapp_business_accounts'] as $edge) {
                    $res = Http::withToken($token)->timeout(12)
                        ->get("{$base}/{$bid}/{$edge}", ['fields' => 'id', 'limit' => 100]);
                    if ($res->successful()) {
                        foreach ((array) $res->json('data', []) as $row) {
                            if (!empty($row['id'])) $wabaIds[] = (string) $row['id'];
                        }
                    }
                }
            }
            $wabaIds = array_values(array_unique($wabaIds));

            Log::info('[WABA-BACKFILL] WABAs found across businesses', [
                'config_id'  => $cfg->id,
                'businesses' => $bizIds,
                'waba_ids'   => $wabaIds,
            ]);

            if (empty($wabaIds)) {
                Log::warning('[WABA-BACKFILL] no WABAs on any business — connection must be re-made', [
                    'config_id' => $cfg->id,
                ]);

                return null;
            }

            // 2) The WABA whose phone_numbers include OUR phone_number_id. Always
            //    verify by phone even when there's a single WABA — a business can
            //    own several, and picking the wrong one would sync the wrong
            //    template set.
            $match = null;
            foreach ($wabaIds as $wid) {
                $pn = Http::withToken($token)->timeout(12)
                    ->get("{$base}/{$wid}/phone_numbers", ['fields' => 'id', 'limit' => 100]);
                if (! $pn->successful()) continue;
                foreach ((array) $pn->json('data', []) as $row) {
                    if ((string) ($row['id'] ?? '') === $phoneId) { $match = $wid; break 2; }
                }
            }
            // Exactly one WABA and no phone match (the phone_numbers edge can be
            // unreadable on some token types) — take it. With one candidate
            // there is nothing to confuse it with.
            if (! $match && count($wabaIds) === 1 && $wabaIds[0] !== $existing) {
                $match = $wabaIds[0];
                Log::info('[WABA-BACKFILL] adopting the only WABA on the business (no phone match)', [
                    'config_id' => $cfg->id, 'waba_id' => $match,
                ]);
            }

            if (! $match) {
                Log::warning('[WABA-BACKFILL] no WABA owns this phone number', [
                    'config_id'       => $cfg->id,
                    'phone_number_id' => $phoneId,
                    'checked'         => $wabaIds,
                ]);

                return null;
            }

            // 3) Persist so every future WABA op (sync, delete, submit) has it.
            $cfg->meta_json = array_merge($meta, ['waba_id' => $match]);
            $cfg->save();
            Log::info('[WABA-BACKFILL] recovered waba_id from business', [
                'config_id'       => $cfg->id,
                'waba_id'         => $match,
                'phone_number_id' => $phoneId,
                'replaced'        => $existing ?: null,
            ]);
            return $match;
        } catch (\Throwable $e) {
            Log::warning('[WABA-BACKFILL] failed: ' . $e->getMessage(), ['config_id' => $cfg->id]);
            return null;
        }
    }
}
