<?php

namespace App\Services\Drip;

use App\Models\Contact;
use App\Models\DripCampaign;
use App\Models\DripSubscriber;
use App\Services\WhatsAppDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enrols contacts into drip campaigns and delivers the steps that are due.
 *
 * Durability is the entire design. Nothing is held in memory between steps:
 * every pending follow-up is a row with `next_send_at`. drain() asks "what is
 * due?" and sends it. A restart mid-sequence costs nothing — the next drain
 * picks the work up. Late is recoverable; lost is not, which is exactly what
 * the Flow Wait node (an in-process setTimeout) gets wrong.
 */
class DripRunner
{
    public function __construct(private readonly WhatsAppDispatcher $dispatcher)
    {
    }

    // -----------------------------------------------------------------
    // Enrolment
    // -----------------------------------------------------------------

    /**
     * Put a contact into a campaign at step 1.
     *
     * Idempotent per (campaign, contact) — a contact re-tagged twice does not
     * get the sequence twice. Returns null when already enrolled or ineligible.
     */
    public function enrol(DripCampaign $campaign, Contact $contact): ?DripSubscriber
    {
        if (! $campaign->isRunning()) {
            return null;
        }

        $first = $campaign->steps()->first();
        if (! $first) {
            return null;   // nothing to send
        }

        $existing = DripSubscriber::where('drip_campaign_id', $campaign->id)
            ->where('contact_id', $contact->id)
            ->first();

        if ($existing) {
            return null;
        }

        $sub = DripSubscriber::create([
            'drip_campaign_id' => $campaign->id,
            'contact_id'       => $contact->id,
            'workspace_id'     => $campaign->workspace_id,
            'status'           => DripSubscriber::STATUS_ACTIVE,
            'current_step'     => 0,
            'next_send_at'     => $this->dueAt($campaign, $first->delaySeconds()),
        ]);

        Log::info('[DRIP] enrolled', [
            'campaign' => $campaign->id,
            'contact'  => $contact->id,
            'due'      => (string) $sub->next_send_at,
        ]);

        return $sub;
    }

    /** Enrol many at once — used by the manual "add audience" action. */
    public function enrolMany(DripCampaign $campaign, iterable $contacts): int
    {
        $n = 0;
        foreach ($contacts as $contact) {
            if ($this->enrol($campaign, $contact)) $n++;
        }

        return $n;
    }

    // -----------------------------------------------------------------
    // Delivery
    // -----------------------------------------------------------------

    /**
     * Send every step that is due, across all active campaigns.
     *
     * Safe to call from anywhere and as often as you like: the row lock plus
     * the next_send_at check mean two concurrent drains cannot double-send.
     *
     * @return int how many steps were delivered
     */
    public function drain(int $limit = 100): int
    {
        $sent = 0;

        $due = DripSubscriber::query()
            ->due()
            ->orderBy('next_send_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($due as $id) {
            try {
                if ($this->deliverOne((int) $id)) $sent++;
            } catch (\Throwable $e) {
                Log::error('[DRIP] step failed', ['subscriber' => $id, 'err' => $e->getMessage()]);
            }
        }

        if ($sent > 0) {
            Log::info('[DRIP] drain complete', ['delivered' => $sent]);
        }

        return $sent;
    }

    /**
     * Deliver one subscriber's next step.
     *
     * The claim is done inside a transaction with a row lock: the subscriber is
     * moved forward BEFORE the send, so a crash mid-send re-sends at worst
     * nothing rather than looping the same step forever.
     */
    private function deliverOne(int $subscriberId): bool
    {
        /** @var DripSubscriber|null $sub */
        $sub = null;
        $step = null;

        DB::transaction(function () use ($subscriberId, &$sub, &$step) {
            $sub = DripSubscriber::whereKey($subscriberId)->lockForUpdate()->first();

            // Re-check under the lock — another drain may have taken it.
            if (! $sub || $sub->status !== DripSubscriber::STATUS_ACTIVE
                || ! $sub->next_send_at || $sub->next_send_at->isFuture()) {
                $sub = null;

                return;
            }

            $campaign = $sub->campaign;
            if (! $campaign || ! $campaign->isRunning()) {
                $sub = null;

                return;
            }

            $step = $campaign->steps()->where('position', '>', $sub->current_step)->first();
            if (! $step) {
                $sub->update([
                    'status'       => DripSubscriber::STATUS_COMPLETED,
                    'next_send_at' => null,
                ]);
                $sub = null;

                return;
            }

            // Quiet hours: push to the window's opening rather than waking a
            // patient at 3am. Re-checked each drain, so it self-corrects.
            $wait = $this->quietHoursDelay($campaign);
            if ($wait) {
                $sub->update(['next_send_at' => $wait]);
                $sub = null;

                return;
            }

            // CLAIM, don't complete. The cursor stays put — only a successful
            // send advances it. Pushing next_send_at into the near future stops
            // a second drain grabbing the same row while this one is sending,
            // and doubles as the retry slot if the send fails or this process
            // dies mid-flight.
            $sub->update([
                'attempts'     => $sub->attempts + 1,
                'next_send_at' => now()->addSeconds($sub->retryDelaySeconds()),
            ]);
        });

        if (! $sub || ! $step) {
            return false;
        }

        $ok = $this->send($sub, $step);

        if ($ok) {
            // Only now is the step really done. Advance and schedule the next.
            $campaign = $sub->campaign;
            $next     = $campaign->steps()->where('position', '>', $step->position)->first();

            $sub->update([
                'current_step' => $step->position,
                'attempts'     => 0,
                'last_error'   => null,
                'last_sent_at' => now(),
                'next_send_at' => $next ? $this->dueAt($campaign, $next->delaySeconds()) : null,
                'status'       => $next ? DripSubscriber::STATUS_ACTIVE : DripSubscriber::STATUS_COMPLETED,
            ]);

            return true;
        }

        // Failed. The claim above already scheduled the retry; give up only
        // after MAX_ATTEMPTS so one bad step cannot strand the whole sequence.
        $sub->refresh();

        if ($sub->attempts >= DripSubscriber::MAX_ATTEMPTS) {
            $campaign = $sub->campaign;
            $next     = $campaign?->steps()->where('position', '>', $step->position)->first();

            Log::warning('[DRIP] step abandoned after max attempts', [
                'subscriber' => $sub->id,
                'step'       => $step->position,
                'attempts'   => $sub->attempts,
                'error'      => $sub->last_error,
            ]);

            $sub->update([
                'current_step' => $step->position,   // skip past it
                'attempts'     => 0,
                'next_send_at' => $next ? $this->dueAt($campaign, $next->delaySeconds()) : null,
                'status'       => $next ? DripSubscriber::STATUS_ACTIVE : DripSubscriber::STATUS_FAILED,
            ]);
        }

        return false;
    }

    private function send(DripSubscriber $sub, $step): bool
    {
        $contact = $sub->contact;
        if (! $contact) {
            $sub->stop('Contact no longer exists');

            return false;
        }

        $to = Contact::canonicalizePhone($contact->country_code, $contact->mobile);
        if ($to === '') {
            $sub->stop('Contact has no usable mobile number');

            return false;
        }

        $campaign = $sub->campaign;
        $body     = $this->render((string) $step->body, $contact);

        // Template steps carry positional parameters. Meta rejects a template
        // whose parameter count doesn't match what was approved, so these come
        // from the step's var_map rather than being guessed.
        $sendMeta = [];
        if ($step->template_id && is_array($step->var_map) && $step->var_map) {
            $sendMeta['template_params'] = array_map(
                fn ($field) => $this->contactField($contact, (string) $field),
                array_values($step->var_map)
            );
        }

        $result = $this->dispatcher->sendRaw([
            'to_number'    => $to,
            'from_number'  => null,
            'body'         => $body,
            'media_path'   => $step->media_path ?: null,
            'media_type'   => $step->media_type ?: null,
            'template_id'  => $step->template_id ?: null,
            'meta'         => $sendMeta ?: null,
            'workspace_id' => $campaign->workspace_id,
            'provider'     => $campaign->provider ?: null,
        ], $campaign->user_id, 'W');

        $ok = (bool) ($result['ok'] ?? false);

        $sub->increment($ok ? 'sent_count' : 'fail_count');

        if (! $ok) {
            // Keep the provider's reason on the row. Without it the subscriber
            // list shows "failed" with no way to tell a dead number from a
            // disconnected device — one needs the contact fixed, the other
            // fixes itself on retry.
            $sub->forceFill([
                'last_error' => mb_substr((string) ($result['error'] ?? 'Send failed'), 0, 191),
            ])->save();
        }

        Log::info('[DRIP] step sent', [
            'campaign'   => $campaign->id,
            'subscriber' => $sub->id,
            'step'       => $step->position,
            'ok'         => $ok,
            'error'      => $result['error'] ?? null,
        ]);

        return $ok;
    }

    // -----------------------------------------------------------------
    // Automatic enrolment
    // -----------------------------------------------------------------
    //
    // Called from the SAME places FlowEnrollmentService is already hooked, so
    // drips ride proven event points rather than a second set that can drift.
    // Every one is best-effort: a drip must never break the action that
    // triggered it (saving a contact, closing a deal).

    public function onContactCreated(Contact $contact): void
    {
        $this->fire($contact, 'contact_created');
    }

    /** $tagId is what the hook has; campaigns may be keyed by id OR name. */
    public function onTagAdded(Contact $contact, int $tagId): void
    {
        $name = null;
        try {
            $name = \App\Models\Tag::whereKey($tagId)->value('name');
        } catch (\Throwable $e) {
            // fall back to matching on the id alone
        }

        $values = array_values(array_filter([
            (string) $tagId,
            $name ? (string) $name : null,
        ]));

        // A tag can BOTH end one sequence and start another — "booked" stops
        // the chase-up drip and starts the pre-appointment one. Goals are
        // settled first so a contact can't be enrolled and immediately exited.
        $this->reachGoal($contact, 'tag_added', $values);
        $this->fire($contact, 'tag_added', $values);
    }

    /** The patient booked — every drip chasing that booking should stop. */
    public function onAppointmentBooked(Contact $contact): void
    {
        $this->reachGoal($contact, 'appointment_booked');
    }

    /** They ordered — stop any sequence whose goal was the order. */
    public function onOrderPlaced(Contact $contact): void
    {
        $this->reachGoal($contact, 'order_placed');
    }

    /**
     * Close out every active subscription whose campaign was aiming at exactly
     * this outcome. Continuing to send after the goal is met is the fastest way
     * to make an automation look broken.
     *
     * @param  array<int,string>|null  $values  accepted goal_value matches
     */
    private function reachGoal(Contact $contact, string $goal, ?array $values = null): void
    {
        try {
            $subs = DripSubscriber::query()
                ->where('workspace_id', $contact->workspace_id)
                ->where('contact_id', $contact->id)
                ->where('status', DripSubscriber::STATUS_ACTIVE)
                ->with('campaign')
                ->get();

            foreach ($subs as $sub) {
                $campaign = $sub->campaign;
                if (! $campaign || (string) $campaign->goal_type !== $goal) {
                    continue;
                }

                if ($values !== null) {
                    $want = trim((string) $campaign->goal_value);
                    if ($want === '') continue;   // unconfigured — never matches

                    $hit = false;
                    foreach ($values as $v) {
                        if (strcasecmp($want, (string) $v) === 0) { $hit = true; break; }
                    }
                    if (! $hit) continue;
                }

                $sub->stop('Goal reached: ' . $goal);

                Log::info('[DRIP] goal reached — sequence ended early', [
                    'campaign'   => $campaign->id,
                    'subscriber' => $sub->id,
                    'goal'       => $goal,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[DRIP] goal check failed', ['goal' => $goal, 'err' => $e->getMessage()]);
        }
    }

    public function onGroupJoin(Contact $contact, int $groupId): void
    {
        $this->fire($contact, 'group_added', [(string) $groupId]);
    }

    public function onDealCreated($deal): void
    {
        $contact = $deal->contact ?? null;
        if ($contact instanceof Contact) {
            $this->fire($contact, 'deal_created');
        }
    }

    /**
     * Enrol a contact into every ACTIVE campaign whose trigger matches.
     *
     * @param  array<int,string>|null  $values  accepted trigger_value matches;
     *                                          null means "value is irrelevant"
     */
    private function fire(Contact $contact, string $trigger, ?array $values = null): void
    {
        try {
            $campaigns = DripCampaign::query()
                ->where('workspace_id', $contact->workspace_id)
                ->where('status', DripCampaign::STATUS_ACTIVE)
                ->where('trigger_type', $trigger)
                ->get();

            foreach ($campaigns as $campaign) {
                if ($values !== null) {
                    $want = trim((string) $campaign->trigger_value);
                    // A value-based trigger with nothing configured would match
                    // every tag — treat it as unconfigured and skip.
                    if ($want === '') continue;

                    $hit = false;
                    foreach ($values as $v) {
                        if (strcasecmp($want, (string) $v) === 0) { $hit = true; break; }
                    }
                    if (! $hit) continue;
                }

                $this->enrol($campaign, $contact);
            }
        } catch (\Throwable $e) {
            Log::warning('[DRIP] auto-enrol failed', [
                'trigger' => $trigger,
                'contact' => $contact->id ?? null,
                'err'     => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Stop conditions
    // -----------------------------------------------------------------

    /**
     * A contact replied — halt their sequences that ask to stop on reply.
     * This is what separates a drip from spam: the moment a patient answers,
     * the automated chase stops and a human takes over.
     */
    public function stopOnReply(int $workspaceId, int $contactId): int
    {
        $subs = DripSubscriber::where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->where('status', DripSubscriber::STATUS_ACTIVE)
            ->with('campaign')
            ->get();

        $n = 0;
        foreach ($subs as $sub) {
            if ($sub->campaign?->stop_on_reply) {
                $sub->stop('Contact replied');
                $n++;
            }
        }

        return $n;
    }

    /**
     * Send ONE step to an arbitrary number, right now, ignoring the schedule.
     *
     * Shipping an automation tool with no way to preview a message means the
     * first person to see a typo is the customer. This runs the real send path
     * — same renderer, same template parameters, same dispatcher — so what the
     * operator sees is exactly what a contact would get, not an approximation.
     *
     * @return array{ok:bool, error:?string}
     */
    public function testSend(DripCampaignStep $step, string $toNumber, ?Contact $asContact = null): array
    {
        $to = preg_replace('/\D+/', '', $toNumber);
        if ($to === '') {
            return ['ok' => false, 'error' => __('Enter a number to send the test to.')];
        }

        $campaign = $step->campaign;
        if (! $campaign) {
            return ['ok' => false, 'error' => __('Step is not attached to a campaign.')];
        }

        // Render against a real contact when one is given, otherwise a stand-in
        // so merge tags show something recognisable rather than blank gaps.
        $contact = $asContact ?: new Contact([
            'name'   => __('Test Contact'),
            'mobile' => $to,
            'email'  => 'test@example.com',
        ]);

        $sendMeta = [];
        if ($step->template_id && is_array($step->var_map) && $step->var_map) {
            $sendMeta['template_params'] = array_map(
                fn ($field) => $this->contactField($contact, (string) $field),
                array_values($step->var_map)
            );
        }

        try {
            $result = $this->dispatcher->sendRaw([
                'to_number'    => $to,
                'from_number'  => null,
                'body'         => $this->render((string) $step->body, $contact),
                'media_path'   => $step->media_path ?: null,
                'media_type'   => $step->media_type ?: null,
                'template_id'  => $step->template_id ?: null,
                'meta'         => $sendMeta ?: null,
                'workspace_id' => $campaign->workspace_id,
                'provider'     => $campaign->provider ?: null,
            ], $campaign->user_id, 'W');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        Log::info('[DRIP] test send', [
            'campaign' => $campaign->id,
            'step'     => $step->position,
            'to'       => $to,
            'ok'       => $result['ok'] ?? false,
        ]);

        return [
            'ok'    => (bool) ($result['ok'] ?? false),
            'error' => $result['error'] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    // Analytics
    // -----------------------------------------------------------------

    /**
     * Per-campaign numbers, including a per-STEP funnel.
     *
     * The funnel is the number that matters: a sequence where everyone drops
     * out at step 2 has a step-2 problem, and a total-sends figure hides that
     * completely. current_step is the cursor of the last step SENT, so the
     * count of subscribers at or past each position is exactly its reach.
     *
     * @return array{reached:array<int,int>, totals:array<string,int>, failing:int}
     */
    public function stats(DripCampaign $campaign): array
    {
        $subs = $campaign->subscribers()
            ->get(['status', 'current_step', 'sent_count', 'fail_count', 'attempts']);

        $reached = [];
        foreach ($campaign->steps as $step) {
            $reached[$step->position] = $subs->where('current_step', '>=', $step->position)->count();
        }

        return [
            'reached' => $reached,
            'totals'  => [
                'enrolled'  => $subs->count(),
                'active'    => $subs->where('status', DripSubscriber::STATUS_ACTIVE)->count(),
                'completed' => $subs->where('status', DripSubscriber::STATUS_COMPLETED)->count(),
                'stopped'   => $subs->where('status', DripSubscriber::STATUS_STOPPED)->count(),
                'failed'    => $subs->where('status', DripSubscriber::STATUS_FAILED)->count(),
                'sent'      => (int) $subs->sum('sent_count'),
                'failed_sends' => (int) $subs->sum('fail_count'),
            ],
            // Currently mid-retry — the early warning that something is wrong
            // with the device or the numbers, before it shows up as "failed".
            'failing' => $subs->where('attempts', '>', 0)->count(),
        ];
    }

    // -----------------------------------------------------------------
    // Timing
    // -----------------------------------------------------------------

    /** When a step waiting $seconds should fire, respecting quiet hours. */
    private function dueAt(DripCampaign $campaign, int $seconds): Carbon
    {
        $at = now()->addSeconds(max(0, $seconds));

        return $this->shiftOutOfQuietHours($campaign, $at);
    }

    /** Null when sending is allowed now, else when to retry. */
    private function quietHoursDelay(DripCampaign $campaign): ?Carbon
    {
        $shifted = $this->shiftOutOfQuietHours($campaign, now());

        return $shifted->gt(now()->addMinute()) ? $shifted : null;
    }

    /**
     * Move a moment forward to the next allowed hour.
     * Handles a window that wraps midnight (e.g. 21:00 → 09:00).
     */
    private function shiftOutOfQuietHours(DripCampaign $campaign, Carbon $at): Carbon
    {
        $start = $campaign->quiet_start_hour;
        $end   = $campaign->quiet_end_hour;

        if ($start === null || $end === null || $start === $end) {
            return $at;
        }

        $tz    = $campaign->timezone ?: 'UTC';
        $local = $at->copy()->setTimezone($tz);
        $hour  = (int) $local->format('G');

        $inQuiet = $start < $end
            ? ($hour >= $start && $hour < $end)          // same-day window
            : ($hour >= $start || $hour < $end);         // wraps midnight

        if (! $inQuiet) {
            return $at;
        }

        $open = $local->copy()->setTime($end, 0);
        if ($open->lte($local)) {
            $open->addDay();
        }

        return $open->setTimezone($at->getTimezone());
    }

    /** One contact field by name, for filling a template's positional slots. */
    private function contactField(Contact $contact, string $field): string
    {
        $name = trim((string) ($contact->name ?? ''));

        return match ($field) {
            'name'       => $name,
            'first_name' => $name !== '' ? explode(' ', $name)[0] : '',
            'mobile'     => (string) ($contact->mobile ?? ''),
            'email'      => (string) ($contact->email ?? ''),
            default      => '',
        };
    }

    /** Merge tags a drip body may use. */
    private function render(string $body, Contact $contact): string
    {
        $name  = trim((string) ($contact->name ?? ''));
        $first = $name !== '' ? explode(' ', $name)[0] : '';

        return strtr($body, [
            '{{name}}'       => $name,
            '{{first_name}}' => $first,
            '{{mobile}}'     => (string) ($contact->mobile ?? ''),
            '{{email}}'      => (string) ($contact->email ?? ''),
        ]);
    }
}
