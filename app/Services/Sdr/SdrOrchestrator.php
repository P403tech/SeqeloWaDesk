<?php

namespace App\Services\Sdr;

use App\Models\Contact;
use App\Models\Flow;
use App\Models\SdrCampaign;
use App\Models\SdrEnrollment;
use App\Services\Flow\FlowEnrollmentService;
use App\Services\LeadScoring\ConversionStopService;
use App\Services\LeadScoring\LeadScoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the AI SDR: enrols qualified leads into a cadence flow, hands them to a
 * human sales team once they score high enough, and stops chasing the moment
 * they reply or convert. It orchestrates the EXISTING engines (flow cadence,
 * lead scoring, conversion-stop) rather than duplicating sending. Every method
 * is best-effort — SDR logic must never break a message flow.
 */
class SdrOrchestrator
{
    /**
     * Enrol a contact into an SDR campaign (idempotent per campaign+contact).
     * Honors the min-score gate and optional conditions, and starts the cadence
     * flow. Returns the enrollment, or null when the lead wasn't eligible.
     */
    public static function enroll(Contact $contact, SdrCampaign $campaign, array $context = []): ?SdrEnrollment
    {
        try {
            if (! $campaign->is_active) {
                return null;
            }
            $score = (int) ($contact->lead_score ?? 0);
            if ($score < (int) $campaign->enroll_min_score) {
                return null;
            }
            if (! empty($campaign->enroll_conditions)
                && ! LeadScoringService::matches($campaign->enroll_conditions, $context)) {
                return null;
            }

            $existing = SdrEnrollment::where('sdr_campaign_id', $campaign->id)
                ->where('contact_id', $contact->id)->first();
            if ($existing && $existing->status === 'active') {
                return $existing;                     // already running
            }

            $enrollment = SdrEnrollment::updateOrCreate(
                ['sdr_campaign_id' => $campaign->id, 'contact_id' => $contact->id],
                [
                    'workspace_id'    => $campaign->workspace_id,
                    'status'          => 'active',
                    'score_at_enroll' => $score,
                    'enrolled_at'     => now(),
                    'routed_at'       => null,
                    'stopped_at'      => null,
                    'stopped_reason'  => null,
                ]
            );

            // Kick off the cadence via the existing flow engine (multi-step,
            // multi-channel, AI, delays, booking all handled there).
            if ($campaign->flow_id) {
                $flow = Flow::where('workspace_id', $campaign->workspace_id)
                    ->where('id', $campaign->flow_id)->first();
                if ($flow) {
                    app(FlowEnrollmentService::class)->enroll($contact, $flow);
                }
            }

            Log::info('[SDR] lead enrolled', [
                'campaign' => $campaign->id, 'contact' => $contact->id, 'score' => $score,
            ]);
            return $enrollment;
        } catch (\Throwable $e) {
            Log::warning('[SDR] enroll failed: ' . $e->getMessage(), ['contact' => $contact->id ?? null]);
            return null;
        }
    }

    /**
     * React to a lead's score changing: any active enrollment whose campaign
     * defines a route_score the lead now meets is handed to the sales team and
     * its cadence stopped (a human takes over — no more auto-chasing).
     */
    public static function onScore(Contact $contact): void
    {
        try {
            $score = (int) ($contact->lead_score ?? 0);
            $enrollments = SdrEnrollment::active()->where('contact_id', $contact->id)->get();
            foreach ($enrollments as $enr) {
                $campaign = $enr->campaign;
                if (! $campaign || $campaign->route_score === null) {
                    continue;
                }
                if ($score >= (int) $campaign->route_score) {
                    self::route($enr, $campaign, $contact, $score);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SDR] onScore failed: ' . $e->getMessage(), ['contact' => $contact->id ?? null]);
        }
    }

    /** Hand a qualified lead to the sales team + stop the cadence. */
    private static function route(SdrEnrollment $enr, SdrCampaign $campaign, Contact $contact, int $score): void
    {
        $enr->forceFill(['status' => 'routed', 'routed_at' => now()])->save();

        // Stop the auto-cadence — a human owns it now.
        ConversionStopService::stop($contact, 'routed_to_sales');

        // Assign the lead's open conversations to the sales team, if configured
        // and the schema supports team assignment.
        if ($campaign->route_team_id
            && Schema::hasTable('conversations')
            && Schema::hasColumn('conversations', 'contact_id')
            && Schema::hasColumn('conversations', 'assignee_team_id')) {
            DB::table('conversations')
                ->where('workspace_id', $campaign->workspace_id)
                ->where('contact_id', $contact->id)
                ->whereIn('inbox_status', ['open', 'pending'])
                ->update(['assignee_team_id' => $campaign->route_team_id, 'updated_at' => now()]);
        }

        Log::info('[SDR] lead routed to sales', [
            'campaign' => $campaign->id, 'contact' => $contact->id,
            'score' => $score, 'team' => $campaign->route_team_id,
        ]);
    }

    /** Lead replied — stop the cadence for campaigns configured to. */
    public static function onReply(Contact $contact): void
    {
        self::stopWhere($contact, 'replied', fn ($c) => (bool) $c->stop_on_reply);
    }

    /** Lead converted (deal won / paid) — stop the cadence + mark converted. */
    public static function onConvert(Contact $contact): void
    {
        self::stopWhere($contact, 'converted', fn ($c) => (bool) $c->stop_on_convert, 'converted');
    }

    /**
     * Stop active enrollments for a contact whose campaign matches $want, set
     * the terminal status, and halt any running sequences.
     */
    private static function stopWhere(Contact $contact, string $reason, callable $want, string $status = 'stopped'): void
    {
        try {
            $enrollments = SdrEnrollment::active()->where('contact_id', $contact->id)->get();
            $stoppedAny = false;
            foreach ($enrollments as $enr) {
                $campaign = $enr->campaign;
                if (! $campaign || ! $want($campaign)) {
                    continue;
                }
                $enr->forceFill([
                    'status' => $status, 'stopped_at' => now(), 'stopped_reason' => $reason,
                ])->save();
                $stoppedAny = true;
            }
            if ($stoppedAny) {
                ConversionStopService::stop($contact, $reason);
                Log::info('[SDR] cadence stopped', ['contact' => $contact->id, 'reason' => $reason]);
            }
        } catch (\Throwable $e) {
            Log::warning('[SDR] stop failed: ' . $e->getMessage(), ['contact' => $contact->id ?? null]);
        }
    }
}
