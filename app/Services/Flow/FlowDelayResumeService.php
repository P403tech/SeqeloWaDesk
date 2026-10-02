<?php

namespace App\Services\Flow;

use App\Models\FlowDelayResume;
use App\Models\FlowSubscriber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Durable long-delay engine. Two halves:
 *
 *   park()      — Node calls this (POST /api/flow-node/delay-park) when it hits
 *                 a LONG duration-delay node. We store a pending row with a
 *                 resume_at instead of Node holding an in-process timer.
 *   drainDue()  — the heartbeat sweep (FlowDelayResumeSweeper) calls this. It
 *                 claims each due row and POSTs it back to Node
 *                 (/api/flow/resume-delay), which continues the flow from the
 *                 delay node. Same no-cron, bounded-batch pattern as
 *                 CampaignFollowupService.
 *
 * Durability lives in flow_delay_resumes.resume_at — a Node restart delays a
 * pending resume, never loses it.
 */
class FlowDelayResumeService
{
    /** A resume that keeps failing to reach Node is abandoned after this many tries. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Record a long delay as a durable resume. Idempotent per (session_key,
     * node_id): a re-parked same node (flow looped back) replaces the pending
     * row rather than stacking duplicates.
     */
    public function park(array $data): FlowDelayResume
    {
        $sessionKey = (string) ($data['session_key'] ?? '');
        $nodeId     = (string) ($data['node_id'] ?? '');

        // Drop any earlier still-pending resume for this exact session+node so a
        // re-entry can't double-fire later.
        if ($sessionKey !== '' && $nodeId !== '') {
            FlowDelayResume::where('session_key', $sessionKey)
                ->where('node_id', $nodeId)
                ->whereIn('status', [FlowDelayResume::STATUS_PENDING, FlowDelayResume::STATUS_SENDING])
                ->update(['status' => FlowDelayResume::STATUS_CANCELLED]);
        }

        $seconds = max(1, (int) ($data['resume_seconds'] ?? 0));

        return FlowDelayResume::create([
            'flow_id'            => (int) ($data['flow_id'] ?? 0),
            'flow_subscriber_id' => ($data['flow_subscriber_id'] ?? null) ?: null,
            'session_key'        => $sessionKey,
            'device_phone'       => (string) ($data['device_phone'] ?? ''),
            'customer_phone'     => (string) ($data['customer_phone'] ?? ''),
            'node_id'            => $nodeId,
            'provider'           => ($data['provider'] ?? null) ?: null,
            'variables'          => is_array($data['variables'] ?? null) ? $data['variables'] : [],
            'resume_at'          => now()->addSeconds($seconds),
            'status'             => FlowDelayResume::STATUS_PENDING,
        ]);
    }

    /**
     * Fire every resume whose time has come. Bounded batch per tick. Returns the
     * number actually handed to Node (done), not counting cancels/retries.
     */
    public function drainDue(int $limit = 100): int
    {
        $due = FlowDelayResume::query()
            ->where('status', FlowDelayResume::STATUS_PENDING)
            ->where('resume_at', '<=', now())
            ->orderBy('resume_at')
            ->limit($limit)
            ->get();

        if ($due->isEmpty()) return 0;

        $nodeUrl = $this->nodeUrl();
        if ($nodeUrl === '') {
            Log::warning('[FLOW-DELAY] no Node URL configured — cannot resume delayed flows');
            return 0;
        }

        $resumed = 0;
        foreach ($due as $row) {
            // Claim atomically so a concurrent tick can't grab the same row.
            $claimed = FlowDelayResume::where('id', $row->id)
                ->where('status', FlowDelayResume::STATUS_PENDING)
                ->update(['status' => FlowDelayResume::STATUS_SENDING]);
            if (!$claimed) continue;
            // Re-sync the model to the just-claimed row: the atomic claim above
            // was a query-builder write, so $row's in-memory status is stale
            // ('pending'). Without this refresh a later $row->update(status:
            // 'pending') would be a no-op (Eloquent sees no change) and the row
            // would be stranded in 'sending', never retried.
            $row->refresh();

            // Don't resume a run that already finished (e.g. the operator ended it
            // or it completed another way). Only checkable when it has a row.
            if ($row->flow_subscriber_id) {
                $status = FlowSubscriber::whereKey($row->flow_subscriber_id)->value('status');
                if (in_array($status, ['completed', 'failed'], true)) {
                    $row->update(['status' => FlowDelayResume::STATUS_CANCELLED]);
                    continue;
                }
            }

            if ($this->postResume($nodeUrl, $row)) {
                $row->update(['status' => FlowDelayResume::STATUS_DONE, 'resumed_at' => now()]);
                $resumed++;
            } else {
                // Transient failure — retry on a later tick with a short backoff,
                // or give up after MAX_ATTEMPTS so a dead row can't loop forever.
                $attempts = (int) $row->attempts + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $row->update(['status' => FlowDelayResume::STATUS_FAILED, 'attempts' => $attempts]);
                } else {
                    $row->update([
                        'status'    => FlowDelayResume::STATUS_PENDING,
                        'attempts'  => $attempts,
                        'resume_at' => now()->addSeconds(60),
                    ]);
                }
            }
        }

        return $resumed;
    }

    private function postResume(string $nodeUrl, FlowDelayResume $row): bool
    {
        try {
            $r = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/') . '/api/flow/resume-delay', [
                    'flow_id'            => (int) $row->flow_id,
                    'flow_subscriber_id' => $row->flow_subscriber_id,
                    'session_key'        => (string) $row->session_key,
                    'device_phone'       => (string) $row->device_phone,
                    'customer_phone'     => (string) $row->customer_phone,
                    'node_id'            => (string) $row->node_id,
                    'provider'           => $row->provider,
                    'variables'          => (object) ($row->variables ?? []),
                ]);
            if ($r->successful()) return true;
            $row->update(['last_error' => 'Node ' . $r->status() . ': ' . mb_substr((string) $r->body(), 0, 180)]);
            return false;
        } catch (\Throwable $e) {
            $row->update(['last_error' => 'Node unreachable: ' . mb_substr($e->getMessage(), 0, 180)]);
            return false;
        }
    }

    private function nodeUrl(): string
    {
        return (string) (\App\Models\SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
    }
}
