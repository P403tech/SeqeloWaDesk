<?php

namespace App\Http\Controllers\Sms;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\DeviceSmsGateway;
use App\Models\DeviceSmsJob;
use App\Models\InboxMessage;
use App\Services\Push\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SIM-based SMS gateway — device-facing API.
 *
 * A linked Android phone authenticates with its `device_token` in the
 * `X-Gateway-Token` header on EVERY call. The app:
 *   register  → announces itself (fcm token, SIMs) after linking
 *   pull      → fetches queued outbound SMS (marks them dispatched)
 *   report    → posts per-message sent/failed/delivered
 *   heartbeat → keeps the gateway "online" (battery, SIMs)
 *   inbound   → forwards SMS received on the SIM into the team inbox
 *   send      → (test) queue an outbound SMS to this gateway
 *
 * WaDesk enqueues outbound SMS via DeviceGatewayController::enqueue(), which
 * FCM-wakes the phone. All endpoints are JSON; none use session/CSRF.
 */
class DeviceGatewayController extends Controller
{
    private const POLL_INTERVAL = 20;   // seconds the app should poll as a fallback
    private const RECLAIM_MIN    = 3;    // re-queue a dispatched job unreported this long

    /** Resolve + authenticate the calling gateway from the X-Gateway-Token header. */
    private function gateway(Request $request): ?DeviceSmsGateway
    {
        $token = trim((string) $request->header('X-Gateway-Token', ''));
        if ($token === '') {
            return null;
        }
        return DeviceSmsGateway::where('device_token', $token)->first();
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'invalid_gateway_token'], 401);
    }

    private function settings(DeviceSmsGateway $gw): array
    {
        return [
            'poll_interval'       => self::POLL_INTERVAL,
            'delay_ms'            => (int) $gw->delay_ms,
            'default_sim_slot'    => (int) $gw->default_sim_slot,
            'per_sim_daily_quota' => (int) $gw->per_sim_daily_quota,
        ];
    }

    // ── register ─────────────────────────────────────────────────────────
    public function register(Request $request): JsonResponse
    {
        $gw = $this->gateway($request);
        if (! $gw) {
            return $this->unauthorized();
        }
        $data = $request->validate([
            'fcm_token'   => 'nullable|string|max:512',
            'device_name' => 'nullable|string|max:120',
            'sims'        => 'nullable|array',
            'app_version' => 'nullable|string|max:32',
        ]);

        $gw->forceFill(array_filter([
            'fcm_token'    => $data['fcm_token'] ?? null,
            'name'         => $data['device_name'] ?? $gw->name,
            'sims'         => $data['sims'] ?? $gw->sims,
            'app_version'  => $data['app_version'] ?? $gw->app_version,
            'status'       => 'online',
            'last_seen_at' => now(),
        ], fn ($v) => $v !== null))->save();

        Log::info('[DEVICE-SMS] registered', ['gateway' => $gw->id, 'ws' => $gw->workspace_id]);

        return response()->json([
            'ok'           => true,
            'gateway_id'   => $gw->id,
            'workspace_id' => (int) $gw->workspace_id,
            'settings'     => $this->settings($gw),
        ]);
    }

    // ── pull ─────────────────────────────────────────────────────────────
    public function pull(Request $request): JsonResponse
    {
        $gw = $this->gateway($request);
        if (! $gw) {
            return $this->unauthorized();
        }
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        // Re-queue jobs the app pulled but never reported (crash / went offline).
        DeviceSmsJob::where('gateway_id', $gw->id)->where('status', 'dispatched')
            ->where('claimed_at', '<', now()->subMinutes(self::RECLAIM_MIN))
            ->update(['status' => 'pending', 'claimed_at' => null]);

        $jobs = DeviceSmsJob::where('gateway_id', $gw->id)->where('status', 'pending')
            ->orderBy('id')->limit($limit)->get();

        if ($jobs->isNotEmpty()) {
            DeviceSmsJob::whereIn('id', $jobs->pluck('id')->all())
                ->update(['status' => 'dispatched', 'claimed_at' => now()]);
        }

        $gw->forceFill(['status' => 'online', 'last_seen_at' => now()])->save();

        return response()->json([
            'ok'       => true,
            'jobs'     => $jobs->map(fn ($j) => [
                'id'       => $j->id,
                'to'       => $j->to_number,
                'body'     => $j->body,
                'sim_slot' => $j->sim_slot ?? (int) $gw->default_sim_slot,
            ])->values(),
            'settings' => $this->settings($gw),
        ]);
    }

    // ── report ───────────────────────────────────────────────────────────
    public function report(Request $request): JsonResponse
    {
        $gw = $this->gateway($request);
        if (! $gw) {
            return $this->unauthorized();
        }
        $data = $request->validate([
            'results'          => 'required|array',
            'results.*.id'     => 'required|integer',
            'results.*.status' => 'required|in:sent,failed,delivered',
            'results.*.error'  => 'nullable|string|max:255',
        ]);

        $updated = 0;
        foreach ($data['results'] as $res) {
            $job = DeviceSmsJob::where('gateway_id', $gw->id)->where('id', $res['id'])->first();
            if (! $job) {
                continue;
            }
            $patch = ['status' => $res['status'], 'error' => $res['error'] ?? null];
            if ($res['status'] === 'sent') {
                $patch['sent_at'] = now();
            }
            if ($res['status'] === 'delivered') {
                $patch['delivered_at'] = now();
                if (! $job->sent_at) {
                    $patch['sent_at'] = now();
                }
            }
            $job->forceFill($patch)->save();
            $updated++;
        }

        $gw->forceFill(['status' => 'online', 'last_seen_at' => now()])->save();

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    // ── heartbeat ────────────────────────────────────────────────────────
    public function heartbeat(Request $request): JsonResponse
    {
        $gw = $this->gateway($request);
        if (! $gw) {
            return $this->unauthorized();
        }
        $data = $request->validate([
            'battery'     => 'nullable|integer',
            'sims'        => 'nullable|array',
            'app_version' => 'nullable|string|max:32',
        ]);

        $gw->forceFill(array_filter([
            'battery'      => $data['battery'] ?? null,
            'sims'         => $data['sims'] ?? $gw->sims,
            'app_version'  => $data['app_version'] ?? $gw->app_version,
            'status'       => 'online',
            'last_seen_at' => now(),
        ], fn ($v) => $v !== null))->save();

        return response()->json([
            'ok'       => true,
            'settings' => $this->settings($gw),
            'pending'  => DeviceSmsJob::where('gateway_id', $gw->id)->where('status', 'pending')->count(),
        ]);
    }

    // ── inbound ──────────────────────────────────────────────────────────
    public function inbound(Request $request): JsonResponse
    {
        $gw = $this->gateway($request);
        if (! $gw) {
            return $this->unauthorized();
        }
        $data = $request->validate([
            'from'     => 'required|string|max:32',
            'body'     => 'required|string',
            'sim_slot' => 'nullable|integer',
        ]);

        try {
            $convoId = $this->ingestInbound($gw, $data['from'], (string) $data['body']);
            return response()->json(['ok' => true, 'conversation_id' => $convoId]);
        } catch (\Throwable $e) {
            Log::warning('[DEVICE-SMS] inbound ingest failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'ingest_failed']);
        }
    }

    // ── send (test / enqueue to THIS gateway) ────────────────────────────
    public function send(Request $request): JsonResponse
    {
        $gw = $this->gateway($request);
        if (! $gw) {
            return $this->unauthorized();
        }
        $data = $request->validate([
            'to'       => 'required|string|max:32',
            'body'     => 'required|string|max:2000',
            'sim_slot' => 'nullable|integer',
        ]);

        $job = self::enqueue($gw, $data['to'], $data['body'], $data['sim_slot'] ?? null, 'api');
        return response()->json(['ok' => true, 'job_id' => $job->id]);
    }

    // ── Internal: enqueue an outbound SMS + wake the phone ───────────────
    public static function enqueue(
        DeviceSmsGateway $gw,
        string $to,
        string $body,
        ?int $simSlot = null,
        string $source = 'api',
        ?string $ref = null
    ): DeviceSmsJob {
        $job = DeviceSmsJob::create([
            'workspace_id' => $gw->workspace_id,
            'gateway_id'   => $gw->id,
            'to_number'    => preg_replace('/[^\d+]/', '', $to),
            'body'         => $body,
            'sim_slot'     => $simSlot,
            'status'       => 'pending',
            'source'       => $source,
            'source_ref'   => $ref,
        ]);
        self::wake($gw);
        return $job;
    }

    /** FCM-wake the phone so it pulls immediately (poll is the fallback). */
    private static function wake(DeviceSmsGateway $gw): void
    {
        if (empty($gw->fcm_token)) {
            return;
        }
        try {
            app(FcmService::class)->sendToTokens(
                [$gw->fcm_token],
                ['title' => 'Messages to send', 'body' => 'You have queued SMS.'],
                ['type' => 'device_sms_jobs', 'gateway_id' => (string) $gw->id],
            );
        } catch (\Throwable $e) {
            Log::warning('[DEVICE-SMS] fcm wake failed: ' . $e->getMessage());
        }
    }

    /** Land an inbound SMS in the unified team inbox (channel = sms). */
    private function ingestInbound(DeviceSmsGateway $gw, string $from, string $body): ?int
    {
        $digits = preg_replace('/\D+/', '', $from);
        $key    = 'sms:gw' . $gw->id . ':' . $digits;

        $convo = Conversation::firstOrCreate(
            ['workspace_id' => $gw->workspace_id, 'channel' => 'sms', 'raw_jid' => $key],
            ['title' => $from, 'status' => 'open', 'last_message_at' => now()],
        );

        InboxMessage::create([
            'conversation_id' => $convo->id,
            'direction'       => 'in',
            'provider'        => 'sms',
            'from_number'     => $from,
            'body'            => $body,
            'status'          => 'received',
            'meta'            => ['gateway_id' => $gw->id, 'via' => 'device_sim'],
            'sent_at'         => now(),
        ]);

        $patch = ['last_message_at' => now()];
        if (\Illuminate\Support\Facades\Schema::hasColumn('conversations', 'last_inbound_at')) {
            $patch['last_inbound_at'] = now();
        }
        $convo->forceFill($patch)->save();

        return $convo->id;
    }
}
