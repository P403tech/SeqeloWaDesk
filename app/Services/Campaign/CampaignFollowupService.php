<?php

namespace App\Services\Campaign;

use App\Models\CampaignFollowup;
use App\Models\CampaignFollowupRun;
use App\Models\Contact;
use App\Models\DripCampaign;
use App\Models\Flow;
use App\Models\WaProviderConfig;
use App\Models\WaTemplate;
use App\Models\WpCampaign;
use App\Models\WpCampaignContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The engine behind Campaign Follow-ups.
 *
 *   scheduleDelayed()  — at SEND time, create a durable pending run per delayed
 *                        rule (due_at = sent_at + delay). Immediate rules get no
 *                        pre-row; they fire straight from the webhook.
 *   onEvent()          — the webhook hook points call this with an immediate
 *                        engagement event (replied / clicked / read / failed);
 *                        it fires every matching active rule, once.
 *   drainDue()         — the sweeper calls this each tick to fire delayed runs
 *                        whose time has come AND whose negative condition still
 *                        holds (e.g. still no reply).
 *
 * Idempotency is the UNIQUE(followup_id, recipient_id) on campaign_followup_runs
 * — a rule fires at most once per recipient, immediate or delayed.
 *
 * 24h-window rule: a trigger that leaves the window CLOSED (no reply / read-only
 * / delivered-only) may only run a TEMPLATE-based action. A free-form action on
 * such a trigger is refused here (Meta would reject it 131047 anyway); the UI
 * greys it out too, this is the server-side backstop.
 */
class CampaignFollowupService
{
    /**
     * Create the durable pending runs for a recipient that was just SENT.
     * Safe to call more than once (firstOrCreate on the unique key).
     */
    public function scheduleDelayed(WpCampaignContact $recipient): void
    {
        $sentAt = $recipient->sent_at;
        if (! $sentAt) return;

        foreach ($this->activeRules($recipient->campaign_id) as $rule) {
            if (! $rule->isDelayed()) continue;
            $delay = max(0, (int) ($rule->delay_minutes ?? 0));
            CampaignFollowupRun::firstOrCreate(
                ['campaign_followup_id' => $rule->id, 'wp_campaign_contact_id' => $recipient->id],
                [
                    'contact_id' => $recipient->contact_id,
                    'due_at'     => $sentAt->copy()->addMinutes($delay),
                    'status'     => CampaignFollowupRun::STATUS_PENDING,
                ]
            );
        }
    }

    /**
     * An immediate engagement event happened for this recipient (called from the
     * webhook). Fire every active immediate rule that matches, once.
     */
    public function onEvent(WpCampaignContact $recipient, string $event): void
    {
        foreach ($this->activeRules($recipient->campaign_id) as $rule) {
            if ($rule->trigger_event !== $event || $rule->isDelayed()) continue;

            $run = CampaignFollowupRun::firstOrCreate(
                ['campaign_followup_id' => $rule->id, 'wp_campaign_contact_id' => $recipient->id],
                ['contact_id' => $recipient->contact_id, 'status' => CampaignFollowupRun::STATUS_PENDING]
            );

            // ATOMIC claim — only the racer that flips pending→fired proceeds, so
            // two concurrent webhooks for the same recipient+event can't both act.
            $claimed = CampaignFollowupRun::whereKey($run->id)
                ->where('status', CampaignFollowupRun::STATUS_PENDING)
                ->update(['status' => CampaignFollowupRun::STATUS_FIRED, 'fired_at' => now()]);
            if (! $claimed) continue; // already handled by another request

            $run->refresh();
            $this->performAction($rule, $recipient, $run);
        }
    }

    /**
     * Sweeper tick — fire delayed runs whose due_at has passed and whose negative
     * condition still holds. Bounded batch, row-locked, status advanced before the
     * send so a crash can't loop (mirrors DripRunner::drain). Returns count fired.
     */
    public function drainDue(int $limit = 100): int
    {
        $fired = 0;
        $rows  = CampaignFollowupRun::due()->orderBy('due_at')->limit($limit)->pluck('id');

        foreach ($rows as $runId) {
            $done = DB::transaction(function () use ($runId) {
                /** @var CampaignFollowupRun|null $run */
                $run = CampaignFollowupRun::whereKey($runId)->lockForUpdate()->first();
                if (! $run || $run->status !== CampaignFollowupRun::STATUS_PENDING) return false;

                $rule = CampaignFollowup::find($run->campaign_followup_id);
                $recipient = WpCampaignContact::find($run->wp_campaign_contact_id);
                if (! $rule || ! $rule->is_active || ! $recipient) {
                    $run->update(['status' => CampaignFollowupRun::STATUS_SKIPPED]);
                    return false;
                }

                // Re-check the negative condition against the recipient's CURRENT
                // state — they may have replied in the meantime.
                if (! $this->conditionStillHolds($rule, $recipient)) {
                    $run->update(['status' => CampaignFollowupRun::STATUS_SKIPPED, 'fired_at' => now()]);
                    return false;
                }

                // Advance BEFORE the send so a crash can't re-fire this row.
                $run->update(['status' => CampaignFollowupRun::STATUS_FIRED, 'fired_at' => now()]);
                $this->performAction($rule, $recipient, $run);
                return true;
            });
            if ($done) $fired++;
        }

        return $fired;
    }

    /**
     * One-time backfill for a rule on a campaign that ALREADY ran — enrol the
     * current recipients who already match the rule's status. Idempotent (the
     * run UNIQUE(followup_id, contact) + atomic claim prevents double-fire, so
     * re-running is safe). Bounded. Returns the count acted on.
     *
     * Powers the flow-builder "Campaign engagement" trigger's "apply to existing
     * recipients now" option.
     */
    public function backfillRule(CampaignFollowup $rule, int $limit = 5000): int
    {
        if (! $rule->is_active) return 0;
        $done = 0;
        WpCampaignContact::where('campaign_id', $rule->campaign_id)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (WpCampaignContact $r) use ($rule, &$done) {
                if (! $this->recipientMatchesEvent($r, $rule->trigger_event)) return;

                $run = CampaignFollowupRun::firstOrCreate(
                    ['campaign_followup_id' => $rule->id, 'wp_campaign_contact_id' => $r->id],
                    ['contact_id' => $r->contact_id, 'status' => CampaignFollowupRun::STATUS_PENDING]
                );
                $claimed = CampaignFollowupRun::whereKey($run->id)
                    ->where('status', CampaignFollowupRun::STATUS_PENDING)
                    ->update(['status' => CampaignFollowupRun::STATUS_FIRED, 'fired_at' => now()]);
                if (! $claimed) return;   // already handled

                $run->refresh();
                $this->performAction($rule, $r, $run);
                $done++;
            });

        return $done;
    }

    /** Does a recipient CURRENTLY satisfy an engagement event (for backfill)? */
    private function recipientMatchesEvent(WpCampaignContact $r, string $event): bool
    {
        if ($r->is_unsubscribed || $r->unsubscribed) return false;

        return match ($event) {
            CampaignFollowup::EVENT_READ              => (bool) $r->read_at,
            CampaignFollowup::EVENT_REPLIED           => (bool) $r->responded_at,
            CampaignFollowup::EVENT_CLICKED_LINK      => (bool) $r->clicked_at,
            CampaignFollowup::EVENT_CLICKED_BUTTON    => (bool) $r->clicked_at,
            CampaignFollowup::EVENT_DELIVERED_NO_READ => $r->delivered_at && ! $r->read_at,
            CampaignFollowup::EVENT_READ_NO_REPLY     => $r->read_at && ! $r->responded_at,
            CampaignFollowup::EVENT_SENT_NO_REPLY     => $r->sent_at && ! $r->responded_at,
            CampaignFollowup::EVENT_NOT_DELIVERED     => $r->sent_at && ! $r->delivered_at,
            CampaignFollowup::EVENT_FAILED            => (string) $r->status === 'failed',
            default                                    => false,
        };
    }

    // ---------------------------------------------------------------------

    /** All active rules for a campaign. Cheap indexed query (campaign_id,
     *  is_active); NOT statically cached — a process-lifetime cache would go
     *  stale when rules are edited or when the sweeper spans many campaigns. */
    private function activeRules(int $campaignId): \Illuminate\Support\Collection
    {
        return CampaignFollowup::where('campaign_id', $campaignId)
            ->where('is_active', true)->orderBy('sort_order')->get();
    }

    /** True while the delayed rule's negative condition is still true. */
    private function conditionStillHolds(CampaignFollowup $rule, WpCampaignContact $r): bool
    {
        if ($r->is_unsubscribed || $r->unsubscribed) return false;

        return match ($rule->trigger_event) {
            CampaignFollowup::EVENT_DELIVERED_NO_READ => $r->delivered_at && ! $r->read_at,
            CampaignFollowup::EVENT_READ_NO_REPLY     => $r->read_at && ! $r->responded_at,
            CampaignFollowup::EVENT_SENT_NO_REPLY     => $r->sent_at && ! $r->responded_at,
            CampaignFollowup::EVENT_NOT_DELIVERED     => $r->sent_at && ! $r->delivered_at,
            default                                    => true,
        };
    }

    /** The action switch. Never throws — a failed action marks the run failed. */
    private function performAction(CampaignFollowup $rule, WpCampaignContact $recipient, CampaignFollowupRun $run): void
    {
        try {
            // Window backstop: a free-form action on a window-closed trigger is
            // refused (the UI already prevents it).
            if ($rule->requiresTemplate() && $rule->action_type === CampaignFollowup::ACTION_SEND_MESSAGE) {
                $run->update(['status' => CampaignFollowupRun::STATUS_SKIPPED,
                    'result_json' => ['error' => 'free-form action not allowed outside 24h window']]);
                return;
            }

            $campaign = WpCampaign::find($recipient->campaign_id);
            if (! $campaign) return;
            $wsId    = (int) $campaign->workspace_id;
            $contact = $recipient->contact_id ? Contact::find($recipient->contact_id) : null;

            $result = match ($rule->action_type) {
                CampaignFollowup::ACTION_START_FLOW     => $this->actStartFlow($rule, $contact, $wsId),
                CampaignFollowup::ACTION_ENROLL_DRIP    => $this->actEnrollDrip($rule, $contact, $wsId),
                CampaignFollowup::ACTION_SEND_TEMPLATE  => $this->actSendTemplate($rule, $campaign, $recipient, $contact, $wsId),
                CampaignFollowup::ACTION_ADD_TAG        => $this->actTag($rule, $contact, true),
                CampaignFollowup::ACTION_REMOVE_TAG     => $this->actTag($rule, $contact, false),
                CampaignFollowup::ACTION_ASSIGN_AGENT   => $this->actAssignAgent($rule, $recipient, $contact, $wsId),
                CampaignFollowup::ACTION_OPT_OUT        => $this->actOptOut($recipient, $contact),
                default                                  => ['skipped' => 'action ' . $rule->action_type . ' not handled'],
            };

            $run->update(['result_json' => $result]);
        } catch (\Throwable $e) {
            Log::warning('[CAMPAIGN-FOLLOWUP] action failed', [
                'rule' => $rule->id, 'recipient' => $recipient->id, 'error' => $e->getMessage(),
            ]);
            $run->update(['status' => CampaignFollowupRun::STATUS_FAILED,
                'result_json' => ['error' => $e->getMessage()]]);
        }
    }

    private function actStartFlow(CampaignFollowup $rule, ?Contact $contact, int $wsId): array
    {
        if (! $contact) return ['skipped' => 'no contact on recipient'];
        $flow = Flow::where('workspace_id', $wsId)->whereKey($rule->action_ref_id)->first();
        if (! $flow) return ['skipped' => 'flow not found'];
        $sub = app(\App\Services\Flow\FlowEnrollmentService::class)
            ->enroll($contact, $flow, (array) ($rule->action_payload_json['variables'] ?? []));
        return ['flow_subscriber_id' => $sub->id ?? null];
    }

    private function actEnrollDrip(CampaignFollowup $rule, ?Contact $contact, int $wsId): array
    {
        if (! $contact) return ['skipped' => 'no contact on recipient'];
        $drip = DripCampaign::where('workspace_id', $wsId)->whereKey($rule->action_ref_id)->first();
        if (! $drip) return ['skipped' => 'drip not found'];
        $sub = app(\App\Services\Drip\DripRunner::class)->enrol($drip, $contact);
        return ['drip_subscriber_id' => $sub->id ?? null];
    }

    private function actSendTemplate(CampaignFollowup $rule, WpCampaign $campaign, WpCampaignContact $recipient, ?Contact $contact, int $wsId): array
    {
        $tpl = WaTemplate::where('workspace_id', $wsId)->whereKey($rule->action_ref_id)->first();
        if (! $tpl) return ['skipped' => 'template not found'];

        $to = $contact
            ? preg_replace('/\D+/', '', (string) ($contact->country_code . $contact->mobile))
            : preg_replace('/\D+/', '', (string) $recipient->phone_number);
        if ($to === '') return ['skipped' => 'no phone'];

        // Reuse the campaign var resolver so {{1}}/{{name}} fill exactly as a
        // normal campaign send would.
        $vars = [];
        try {
            $contactArr = [
                'id' => $contact?->id, 'phone' => $to,
                'first_name' => $contact?->first_name, 'last_name' => $contact?->last_name,
                'name' => $contact?->name, 'email' => $contact?->email,
                'custom_attributes' => is_array($contact?->custom_attributes) ? $contact->custom_attributes : [],
            ];
            $vars = app(\App\Http\Controllers\BroadcastsController::class)
                ->varsForRecipient($tpl, $contactArr, $wsId, $rule->action_payload_json['overrides'] ?? null);
        } catch (\Throwable $e) { /* fall back to template examples */ }

        // WABA send config from the campaign's sending number (device_id is the
        // WaProviderConfig id for a WABA campaign).
        $cfg = $campaign->device_id ? WaProviderConfig::find($campaign->device_id) : null;
        $res = app(\App\Services\Waba\TemplateSender::class)->send($tpl, $to, $vars, $cfg);
        return ['ok' => (bool) ($res['ok'] ?? false), 'wamid' => $res['wamid'] ?? null, 'error' => $res['error'] ?? null];
    }

    private function actTag(CampaignFollowup $rule, ?Contact $contact, bool $add): array
    {
        if (! $contact) return ['skipped' => 'no contact on recipient'];
        $tagId = (int) $rule->action_ref_id;
        if ($add) {
            $contact->tags()->syncWithoutDetaching([$tagId]);
        } else {
            $contact->tags()->detach($tagId);
        }
        return ['tag' => $tagId, 'added' => $add];
    }

    /**
     * Route the recipient's conversation to a workspace operator — e.g. "when
     * they reply, hand the chat to sales". Resolves the conversation by its
     * normalised phone digits (wp_campaign_contacts carries no conversation_id).
     */
    private function actAssignAgent(CampaignFollowup $rule, WpCampaignContact $recipient, ?Contact $contact, int $wsId): array
    {
        $agentId = (int) $rule->action_ref_id;
        if ($agentId <= 0) return ['skipped' => 'no agent set'];

        $digits = preg_replace('/\D+/', '', (string) $recipient->phone_number);
        if ($digits === '' && $contact) {
            $digits = preg_replace('/\D+/', '', (string) ($contact->country_code . $contact->mobile));
        }
        if ($digits === '') return ['skipped' => 'no phone to match a conversation'];

        $conv = \App\Models\Conversation::where('workspace_id', $wsId)
            ->where('contact_digits', $digits)
            ->orderByDesc('last_message_at')->first();
        if (! $conv) return ['skipped' => 'no conversation for this recipient yet'];

        $conv->update(['assignee_user_id' => $agentId]);
        return ['assigned_to' => $agentId, 'conversation_id' => $conv->id];
    }

    private function actOptOut(WpCampaignContact $recipient, ?Contact $contact): array
    {
        $recipient->update(['unsubscribed' => true, 'is_unsubscribed' => true, 'unsubscribed_at' => now()]);
        return ['opted_out' => true];
    }
}
