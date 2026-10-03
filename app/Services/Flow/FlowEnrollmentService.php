<?php

namespace App\Services\Flow;

use App\Models\Contact;
use App\Models\Device;
use App\Models\Flow;
use App\Models\FlowSubscriber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Glues an audience trigger (tag attach / group join / manual enrol) to
 * Node's flow runtime. Replaces the old DripEnrollmentService — same
 * public surface, but reads `flows.trigger_kind/value/device_id` instead
 * of a separate `drip_campaigns` row.
 *
 *   - enroll(contact, flow)          → idempotent + POSTs to Node
 *   - onTagAdded(contact, tagId)     → auto-enroll into tag_added flows
 *   - onConversationTagged(conv,id)  → resolves JID → contact → onTagAdded
 *   - onGroupJoin(contact, groupId)  → auto-enroll into group_join flows
 *
 * Step advancement (delays + branching + messages) lives on the Node side
 * via the existing executeFlowNode runtime. No new Laravel cron.
 */
class FlowEnrollmentService
{
    /**
     * Enroll a contact into a flow. Same idempotency contract as before:
     * the UNIQUE(flow_id, contact_id) constraint blocks double-enrollment;
     * already-active subscribers are no-ops; already-failed ones get the
     * Node POST retried.
     */
    public function enroll(Contact $contact, Flow $flow, array $variables = []): FlowSubscriber
    {
        if (!$this->contactBelongsToWorkspace($contact, (int) $flow->workspace_id)) {
            throw new \RuntimeException('Contact not in flow workspace.');
        }

        $sub = FlowSubscriber::firstOrCreate(
            ['flow_id' => $flow->id, 'contact_id' => $contact->id],
            ['enrolled_at' => now(), 'status' => 'active'],
        );

        if ($sub->wasRecentlyCreated || $sub->status === 'failed') {
            $sub->update(['status' => 'active', 'failed_at' => null, 'failure_reason' => null]);
            // Meta Business Agent coexistence — this is the ONE place every flow
            // trigger (tag / group / new-contact / opt-in / keyword / commerce /
            // manual) actually fires + sends. When Meta's agent is fronting the
            // workspace, record the subscriber but DON'T launch (no second reply).
            $ws = \App\Models\Workspace::find((int) $flow->workspace_id);
            if ($ws && $ws->suppressesOurAutoReply()) {
                Log::info('[FLOW-ENROLL] launch suppressed — Meta Business Agent is fronting this workspace', [
                    'flow_id' => $flow->id, 'contact_id' => $contact->id, 'mode' => $ws->ai_responder_mode,
                ]);
            } else {
                $this->launchFlow($contact, $flow, $sub, $variables);
            }
        }

        return $sub;
    }

    public function onTagAdded(Contact $contact, int $tagId): void
    {
        try {
            foreach ($this->flowsForContact($contact, 'tag_added', $tagId) as $f) {
                try { $this->enroll($contact, $f); }
                catch (\Throwable $e) {
                    Log::warning('[FLOW-ENROLL] enroll failed', [
                        'contact_id' => $contact->id, 'flow_id' => $f->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onTagAdded failed: ' . $e->getMessage());
        }
    }

    /**
     * Conversation-side helper — resolves the JID to a Contact within the
     * conversation's workspace (encrypted-column scan-and-compare) and
     * fires onTagAdded. Called from TeamInboxController + RoutingEngine.
     */
    public function onConversationTagged(\App\Models\Conversation $conv, int $tagId): void
    {
        try {
            $jidDigits = preg_replace('/\D+/', '', (string) $conv->raw_jid);
            if ($jidDigits === '') return;

            $q = Contact::query();
            if ($conv->workspace_id) {
                $q->where('workspace_id', $conv->workspace_id);
            } elseif ($conv->user_id) {
                $q->where('user_id', $conv->user_id);
            } else {
                return;
            }
            $contact = $q->get()->first(function ($c) use ($jidDigits) {
                $stored = Contact::canonicalizePhone($c->country_code, $c->mobile);
                return $stored !== '' && $stored === $jidDigits;
            });
            if ($contact) $this->onTagAdded($contact, $tagId);
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onConversationTagged failed: ' . $e->getMessage());
        }
    }

    public function onGroupJoin(Contact $contact, int $groupId): void
    {
        try {
            $flows = $this->flowsForContact($contact, 'group_join', $groupId);
            // Diagnostic: the ONE line that explains "the flow didn't run". 0 =
            // no PUBLISHED+ACTIVE group_join flow whose trigger_value matches this
            // group id in the contact's workspace (wrong group id / unpublished /
            // stale imported group ref). >0 = matched; the per-flow launch trace
            // below then shows sent vs failed.
            Log::info('[FLOW-ENROLL] onGroupJoin matched flows', [
                'contact_id' => $contact->id, 'group_id' => $groupId,
                'workspace_id' => $contact->workspace_id,
                'matched' => $flows->count(), 'flow_ids' => $flows->pluck('id')->all(),
            ]);
            foreach ($flows as $f) {
                try { $this->enroll($contact, $f); }
                catch (\Throwable $e) {
                    Log::warning('[FLOW-ENROLL] group_join enroll failed', [
                        'contact_id' => $contact->id, 'flow_id' => $f->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onGroupJoin failed: ' . $e->getMessage());
        }
    }

    /** New-contact trigger (Contact::created). trigger_value unused (0). */
    public function onContactCreated(Contact $contact): void
    {
        $this->fireForContact($contact, 'contact_created', 0);
    }

    /** Re-subscribe trigger (is_unsubscribed true→false). */
    public function onOptIn(Contact $contact): void
    {
        $this->fireForContact($contact, 'opt_in', 0);
    }

    /**
     * Commerce trigger — a new order. Resolves the order's customer phone to a
     * saved Contact in the order's workspace, then enrolls into order_placed
     * flows. No saved contact → nothing to message, so skip.
     */
    public function onOrderPlaced(\App\Models\WaOrder $order): void
    {
        try {
            $contact = $this->resolveContactByPhone((int) $order->workspace_id, (string) $order->customer_phone);
            if (!$contact) return;
            $vars = $this->orderFlowVariables($order);
            foreach ($this->flowsForWorkspace((int) $order->workspace_id, 'order_placed', 0) as $f) {
                try { $this->enroll($contact, $f, $vars); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onOrderPlaced failed: ' . $e->getMessage());
        }
    }

    /**
     * Merge tags an order-placed flow can use — notably {{invoice_url}} (the
     * store's PDF invoice when its meta carries one, else the order/pay page),
     * so a flow can SEND the invoice for THIS order via a Send-media/document
     * node. Empty values are dropped so a missing var resolves to blank.
     */
    private function orderFlowVariables(\App\Models\WaOrder $order): array
    {
        $meta     = is_array($order->meta_json) ? $order->meta_json : [];
        $currency = (string) ($order->currency_code ?? '');
        $total    = number_format(((int) $order->total_minor) / 100, 2);
        $number   = (string) ($meta['order_number'] ?? $order->id);
        $invoice  = (string) ($meta['invoice_url'] ?? ($order->payment_link ?? ''));

        return array_filter([
            'name'         => (string) ($order->customer_name ?? ''),
            'first_name'   => (explode(' ', trim((string) ($order->customer_name ?? '')))[0] ?? ''),
            'email'        => (string) ($order->customer_email ?? ''),
            'order_number' => $number,
            'order_name'   => '#' . $number,
            'total'        => trim($total . ' ' . $currency),
            'total_price'  => $total,
            'currency'     => $currency,
            'status'       => (string) ($order->status ?? ''),
            'invoice_url'  => $invoice,
            'order_url'    => (string) ($meta['order_url'] ?? ''),
            'payment_link' => (string) ($order->payment_link ?? ''),
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /**
     * Sales Pipeline bridge — a deal moved into a stage. Enrolls the deal's
     * linked contact into deal_stage_changed flows whose trigger_value matches
     * the destination stage_id. The unique combo Wati/AiSensy don't have.
     */
    public function onDealStageChanged(\App\Models\Deal $deal): void
    {
        try {
            if (!$deal->contact_id) return;
            $contact = Contact::find($deal->contact_id);
            if (!$contact) return;
            foreach ($this->flowsForWorkspace((int) $deal->workspace_id, 'deal_stage_changed', (int) $deal->stage_id) as $f) {
                try { $this->enroll($contact, $f); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onDealStageChanged failed: ' . $e->getMessage());
        }
    }

    /* ==================================================================
     * CRM lifecycle triggers
     *
     * Everything below shares one shape: resolve the record's linked contact,
     * then enrol it into matching flows for BOTH the specific id and the 0
     * ("any") sentinel — so an operator can wire "any won deal" without
     * authoring one flow per pipeline.
     *
     * A record with no linked contact is skipped: a flow's whole job is to
     * message someone, and there is nobody to message.
     * ================================================================== */

    /** A deal was created. value = pipeline_id (0 = any pipeline). */
    public function onDealCreated(\App\Models\Deal $deal): void
    {
        $this->fireForDeal($deal, 'deal_created', (int) $deal->pipeline_id);
    }

    /** A deal's status flipped to won. value = pipeline_id (0 = any). */
    public function onDealWon(\App\Models\Deal $deal): void
    {
        $this->fireForDeal($deal, 'deal_won', (int) $deal->pipeline_id);
    }

    /** A deal's status flipped to lost. value = pipeline_id (0 = any). */
    public function onDealLost(\App\Models\Deal $deal): void
    {
        $this->fireForDeal($deal, 'deal_lost', (int) $deal->pipeline_id);
    }

    /** A deal's owner changed. value = the NEW owner's user_id (0 = any). */
    public function onDealAssigned(\App\Models\Deal $deal, int $userId): void
    {
        $this->fireForDeal($deal, 'deal_assigned', $userId);
    }

    /**
     * A conversation was assigned to an agent. value = user_id (0 = any).
     *
     * Unlike the deal triggers this one already has the contact on the
     * conversation, so no phone scan is needed.
     */
    public function onConversationAssigned(\App\Models\Conversation $conv, int $userId): void
    {
        try {
            $contact = $conv->contact_id ? Contact::find($conv->contact_id) : null;
            if (! $contact) return;

            $vars = array_filter([
                'agent_name'      => (string) (\App\Models\User::whereKey($userId)->value('name') ?? ''),
                'conversation_id' => (string) $conv->id,
            ], fn ($v) => $v !== '');

            foreach ($this->crmFlows((int) $conv->workspace_id, 'conversation_assigned', $userId) as $f) {
                try { $this->enroll($contact, $f, $vars); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onConversationAssigned failed: ' . $e->getMessage());
        }
    }

    /**
     * A follow-up date arrived. Called from the reminder sweeps, which already
     * guarantee once-per-task via their `reminded_at` stamp — so this cannot
     * re-fire on the next sweep even though enrolment itself is idempotent.
     *
     * Accepts either a standalone Task or a deal-scoped DealActivity of
     * type=task; both carry the same due/title shape.
     */
    public function onTaskDue($task): void
    {
        try {
            $wsId = (int) ($task->workspace_id ?? 0);
            if ($wsId <= 0) return;

            // Resolve the contact the task hangs off. A standalone Task points at
            // a contact or a deal via related_type/related_id; a DealActivity
            // always belongs to a deal.
            $contact = null;
            if ($task instanceof \App\Models\DealActivity) {
                $deal    = \App\Models\Deal::find($task->deal_id);
                $contact = ($deal && $deal->contact_id) ? Contact::find($deal->contact_id) : null;
            } else {
                $type = (string) ($task->related_type ?? '');
                $rid  = (int) ($task->related_id ?? 0);
                if ($type === 'contact' && $rid > 0) {
                    $contact = Contact::find($rid);
                } elseif ($type === 'deal' && $rid > 0) {
                    $deal    = \App\Models\Deal::find($rid);
                    $contact = ($deal && $deal->contact_id) ? Contact::find($deal->contact_id) : null;
                }
            }
            if (! $contact) return;

            $vars = array_filter([
                'task_title'  => (string) ($task->title ?? $task->body ?? ''),
                'task_due_at' => (string) ($task->due_at ?? ''),
            ], fn ($v) => $v !== '');

            foreach ($this->crmFlows($wsId, 'task_due', 0) as $f) {
                try { $this->enroll($contact, $f, $vars); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onTaskDue failed: ' . $e->getMessage());
        }
    }

    /**
     * A deal went quiet for `$hours`. Fired by DealIdleSweepService, which owns
     * the once-per-idle-window guard (deals.meta->idle_fired_at) — enrolment
     * alone would not stop a re-fire, because a contact that later leaves a flow
     * can be enrolled again.
     *
     * Matched on the EXACT hour value the flow was configured with, so two flows
     * ("nudge at 48h", "escalate at 168h") coexist without stealing each other's
     * deals. No 0-sentinel here: "any idle window" is meaningless.
     */
    public function onNoActivity(\App\Models\Deal $deal, int $hours): void
    {
        try {
            if (! $deal->contact_id) return;
            $contact = Contact::find($deal->contact_id);
            if (! $contact) return;

            $vars = array_merge($this->dealFlowVariables($deal), ['hours_idle' => (string) $hours]);

            foreach ($this->flowsForWorkspace((int) $deal->workspace_id, 'no_activity', $hours) as $f) {
                try { $this->enroll($contact, $f, $vars); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onNoActivity failed: ' . $e->getMessage());
        }
    }

    /** Shared body for the deal-keyed triggers. */
    private function fireForDeal(\App\Models\Deal $deal, string $kind, int $value): void
    {
        try {
            if (! $deal->contact_id) return;
            $contact = Contact::find($deal->contact_id);
            if (! $contact) return;

            $vars = $this->dealFlowVariables($deal);
            foreach ($this->crmFlows((int) $deal->workspace_id, $kind, $value) as $f) {
                try { $this->enroll($contact, $f, $vars); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            Log::warning("[FLOW-ENROLL] {$kind} failed: " . $e->getMessage());
        }
    }

    /**
     * Flows matching kind for a SPECIFIC id plus the 0 "any" sentinel, de-duped.
     * Without the union an operator would have to author one flow per pipeline
     * (or per agent) to cover "any", which is the common case.
     */
    private function crmFlows(int $workspaceId, string $kind, int $value): \Illuminate\Support\Collection
    {
        $specific = $value > 0 ? $this->flowsForWorkspace($workspaceId, $kind, $value) : collect();
        $any      = $this->flowsForWorkspace($workspaceId, $kind, 0);

        return $specific->concat($any)->unique('id')->values();
    }

    /**
     * Merge tags a CRM-triggered flow can use — so the first message can say
     * "your <deal> worth <value>" instead of a generic blurb. Blank values are
     * dropped so an unset tag renders empty rather than the literal word "null".
     */
    private function dealFlowVariables(\App\Models\Deal $deal): array
    {
        return array_filter([
            'deal_id'       => (string) $deal->id,
            'deal_title'    => (string) ($deal->title ?? ''),
            'deal_value'    => number_format(((int) $deal->value_minor) / 100, 2),
            'deal_currency' => (string) ($deal->currency ?? ''),
            'deal_stage'    => (string) (\App\Models\PipelineStage::whereKey($deal->stage_id)->value('name') ?? ''),
            'deal_pipeline' => (string) (\App\Models\Pipeline::whereKey($deal->pipeline_id)->value('name') ?? ''),
            'deal_owner'    => (string) (\App\Models\User::whereKey($deal->owner_user_id)->value('name') ?? ''),
            'lost_reason'   => (string) ($deal->lost_reason ?? ''),
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /**
     * Inbound-condition triggers — launch `away` / `out_of_hours` flows on ANY
     * inbound message while the workspace is in Away mode / outside its Business
     * Hours. Called from every inbound path (WABA/Twilio/IG dispatcher + Baileys
     * lookup). Enrolment is idempotent (UNIQUE flow_id+contact_id), so each
     * contact launches the flow ONCE — no re-fire on every message.
     *
     * Fast path: the common case (in-hours, not away) resolves the workspace's
     * two booleans and returns BEFORE any flow query or contact scan.
     */
    public function onInboundMessage(int $workspaceId, string $contactPhone): void
    {
        try {
            if ($workspaceId <= 0) return;
            $ws = \App\Models\Workspace::find($workspaceId);
            if (!$ws) return;

            $kinds = $this->inboundFlowKinds($ws);
            if (empty($kinds)) return; // in-hours + not away → nothing to fire

            // Only now (rare) do we pay for flow lookup + contact resolution.
            $flows = collect();
            foreach ($kinds as $kind) {
                $flows = $flows->merge($this->flowsForWorkspace($workspaceId, $kind, 0));
            }
            if ($flows->isEmpty()) return;

            $contact = $this->resolveContactByPhone($workspaceId, $contactPhone)
                ?? $this->makeInboundContact($workspaceId, $contactPhone);
            if (!$contact) return;

            foreach ($flows as $f) {
                try { $this->enroll($contact, $f); }
                catch (\Throwable $e) {
                    Log::warning('[FLOW-ENROLL] inbound-trigger enroll failed', [
                        'flow_id' => $f->id, 'kind' => $f->trigger_kind, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[FLOW-ENROLL] onInboundMessage failed: ' . $e->getMessage());
        }
    }

    /**
     * Which inbound-condition trigger kinds are ELIGIBLE right now for a
     * workspace: 'away' when Away mode is on, 'out_of_hours' when outside the
     * workspace's Business Hours. Pure (no side effects) so it is unit-testable
     * without launching a flow. Away reuses AutoResponderEvaluator's source of
     * truth (workspaces.inbox_away); out_of_hours reuses Workspace::isOutsideBusinessHours().
     */
    public function inboundFlowKinds(\App\Models\Workspace $ws): array
    {
        $kinds = [];
        if ((bool) $ws->inbox_away) {
            $kinds[] = 'away';
        }
        try {
            if ($ws->isOutsideBusinessHours()) {
                $kinds[] = 'out_of_hours';
            }
        } catch (\Throwable $e) { /* no business_hours config → treated as open */ }
        return $kinds;
    }

    /**
     * Create a minimal Contact for an inbound phone that has no saved contact yet,
     * so an away/out-of-hours flow can enrol + run. Mirrors the keyword-flow
     * path's findOrMakeContact; the resolve-scan already proved no duplicate.
     */
    private function makeInboundContact(int $workspaceId, string $phone): ?Contact
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '' || !$workspaceId) return null;
        try {
            $owner = \App\Models\Workspace::find($workspaceId)?->owner_user_id;
            return Contact::create([
                'workspace_id' => $workspaceId,
                'user_id'      => $owner,
                'name'         => $digits,
                'mobile'       => $digits,
            ]);
        } catch (\Throwable $e) {
            Log::debug('[FLOW-ENROLL] makeInboundContact failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Shared body for contact-keyed event triggers. */
    private function fireForContact(Contact $contact, string $kind, int $value): void
    {
        try {
            foreach ($this->flowsForContact($contact, $kind, $value) as $f) {
                try { $this->enroll($contact, $f); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            Log::warning("[FLOW-ENROLL] {$kind} failed: " . $e->getMessage());
        }
    }

    /** Find the saved contact whose stored number matches a raw phone. */
    private function resolveContactByPhone(int $workspaceId, string $phone): ?Contact
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '' || !$workspaceId) return null;
        return Contact::where('workspace_id', $workspaceId)->get()->first(function ($c) use ($digits) {
            $stored = Contact::canonicalizePhone($c->country_code, $c->mobile);
            return $stored !== '' && $stored === $digits;
        });
    }

    /** Active+published flows in a workspace matching kind+value. */
    private function flowsForWorkspace(int $workspaceId, string $triggerKind, int $triggerValue): \Illuminate\Support\Collection
    {
        if (!$workspaceId) return collect();
        return Flow::query()
            ->where('is_active', true)
            ->where('is_published', true)
            ->where('trigger_kind', $triggerKind)
            ->where('trigger_value', $triggerValue)
            ->where('workspace_id', $workspaceId)
            ->get();
    }

    /**
     * Resolve active flows for the contact's workspace(s) that match the
     * given trigger kind + value.
     *
     * The contact's OWN `workspace_id` is authoritative and comes first. The
     * old code derived the workspace purely from `owner.workspaces()`, which
     * broke every contact whose `user_id` is NOT a member of its workspace —
     * e.g. a contact created by an admin under impersonation gets user_id=1,
     * and user 1 isn't in workspace 27, so a published group_join/tag flow in
     * ws27 was skipped (the reported "[FLOW-ENROLL] matched:0" despite a
     * correctly-configured, published flow). We still fold in the owner's
     * workspaces as a fallback so a contact with no stamped workspace_id keeps
     * working.
     */
    private function flowsForContact(Contact $contact, string $triggerKind, int $triggerValue): \Illuminate\Support\Collection
    {
        $wsIds = collect();
        if ($contact->workspace_id) {
            $wsIds->push((int) $contact->workspace_id);
        }

        $owner = \App\Models\User::find($contact->user_id);
        if ($owner) {
            foreach ($owner->workspaces()->pluck('workspaces.id') as $wid) {
                if (!$wsIds->contains($wid)) $wsIds->push($wid);
            }
            if ($owner->current_workspace_id && !$wsIds->contains($owner->current_workspace_id)) {
                $wsIds->push($owner->current_workspace_id);
            }
        }
        if ($wsIds->isEmpty()) return collect();

        return Flow::query()
            ->where('is_active', true)
            ->where('is_published', true)
            ->where('trigger_kind', $triggerKind)
            ->where('trigger_value', $triggerValue)
            ->whereIn('workspace_id', $wsIds)
            ->get();
    }

    /**
     * POST to Node to spawn the flow session. Node's existing flow runtime
     * (executeFlowNode → executeTimeDelayNode) owns delay timing from here.
     */
    private function launchFlow(Contact $contact, Flow $flow, FlowSubscriber $sub, array $variables = []): void
    {
        // INSTAGRAM FLOWS DO NOT GO THROUGH THE WHATSAPP SENDER.
        //
        // Everything below resolves a WhatsApp sender phone and then the
        // CONTACT'S mobile number. An Instagram commenter has neither: they are
        // an IGSID, not a phone. So an IG flow enrolled from a comment used to
        // fall straight through to "Contact has no usable mobile number", mark
        // the subscriber failed, and send nothing — no error the operator could
        // see, which is exactly the reported "I comment and nothing happens".
        //
        // The IG runtime lives in Node behind IgFlowBridge::handoff(), which
        // takes the IGSID and (for comment→DM) the comment id so a
        // `ig_reply_comment` node can post its public reply.
        if ((string) $flow->flow_type === 'instagram') {
            $this->launchInstagramFlow($contact, $flow, $sub, $variables);

            return;
        }

        // Multi-engine: resolve the SENDER PHONE. Honor the FLOW'S OWN chosen
        // sender first — the Trigger node stores it as `provider` (engine) +
        // `trigger_device_id`. Only when the flow has no explicit engine do we
        // fall back to the workspace's active engine. Without this, a flow whose
        // trigger is e.g. "waba:13" but whose workspace's PRIMARY engine is
        // Baileys took the Baileys branch, found no paired device, and failed
        // with "No connected baileys sender" — so a group/tag/new-contact flow on
        // a WABA number never sent (the reported "flow not running").
        $engines = [
            \App\Services\WorkspaceEngine::ENGINE_BAILEYS,
            \App\Services\WorkspaceEngine::ENGINE_WABA,
            \App\Services\WorkspaceEngine::ENGINE_TWILIO,
        ];
        $engine = in_array((string) $flow->provider, $engines, true)
            ? (string) $flow->provider
            : \App\Services\WorkspaceEngine::for($flow->workspace_id);
        $devicePhone = '';
        if ($engine === \App\Services\WorkspaceEngine::ENGINE_BAILEYS) {
            $device = $this->resolveDevice($flow);
            if ($device) {
                $devicePhone = preg_replace('/\D+/', '', (string) ($device->country_code . $device->phone_number));
            }
        } else {
            // Prefer the exact provider config the trigger node named
            // (trigger_device_id); fall back to the workspace's primary connected
            // number for that engine.
            $cfg = null;
            if ($flow->trigger_device_id) {
                $cfg = \App\Models\WaProviderConfig::query()
                    ->where('workspace_id', $flow->workspace_id)
                    ->where('id', $flow->trigger_device_id)
                    ->where('provider', $engine)
                    ->where('status', \App\Models\WaProviderConfig::STATUS_CONNECTED)
                    ->first();
            }
            $cfg = $cfg ?: \App\Models\WaProviderConfig::query()
                ->where('workspace_id', $flow->workspace_id)
                ->where('provider', $engine)
                ->where('status', \App\Models\WaProviderConfig::STATUS_CONNECTED)
                ->orderByDesc('is_primary')
                ->orderByDesc('id')
                ->first();
            $devicePhone = $cfg ? preg_replace('/\D+/', '', (string) $cfg->phone_number) : '';
        }
        if ($devicePhone === '') {
            Log::warning('[FLOW-ENROLL] launch aborted — no connected sender', [
                'flow_id' => $flow->id, 'contact_id' => $contact->id, 'engine' => $engine,
                'flow_provider' => $flow->provider, 'trigger_device_id' => $flow->trigger_device_id,
                'hint' => 'Connect the number the flow trigger picks (or set the workspace engine to match it).',
            ]);
            $sub->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => 'No connected ' . $engine . ' sender for this workspace']);
            return;
        }

        // canonicalizePhone(), NOT a raw concat: `mobile` is stored WITH the
        // country code already in it for almost every contact (232 of 238 on a
        // sampled workspace), so `country_code . mobile` doubles the prefix —
        // "+1" + "12291807944" became "112291807944". That is a different,
        // wrong number to send to, and it was long enough to trip the inbound
        // LID guard, producing a numberless "WhatsApp contact" thread that
        // never merged with the customer's real chat. The helper only prefixes
        // when the number does not already start with the code.
        $recipient = Contact::canonicalizePhone($contact->country_code, $contact->mobile);
        if ($recipient === '') {
            $sub->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => 'Contact has no usable mobile number']);
            return;
        }

        // Canonical Laravel→Node URL — matches WaCampaignsController +
        // WaCallingWebhookController. (The old service used NODE_BRIDGE_URL,
        // a one-off that broke installs that only set SERVER_URL.)
        $nodeUrl = (string) (\App\Models\SystemSetting::get('baileys_server_url', '') ?: config('bridge.url'));
        if ($nodeUrl === '') {
            $sub->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => 'Node bridge URL not configured (baileys_server_url / SERVER_URL)']);
            return;
        }

        try {
            $r = Http::withHeaders([
                    'X-Node-Token' => node_token(),
                ])
                ->timeout(15)
                ->acceptJson()
                ->post(rtrim($nodeUrl, '/') . '/api/flow/start/' . rawurlencode($devicePhone), [
                    'flowId'            => $flow->id,
                    'targetPhoneNumber' => $recipient,
                    'flowSubscriberId'  => $sub->id,
                    // Multi-engine hint — the flow runtime also resolves the
                    // engine from the sender phone's settings, but forwarding
                    // it keeps Node's routing explicit for WABA/Twilio flows.
                    'provider'          => $engine,
                    // Personalization — lets {{name}}/{{first_name}}/{{email}}
                    // resolve in message nodes for audience/event triggers.
                    'name'              => trim((string) ($contact->name ?? '')),
                    'first_name'        => (explode(' ', trim((string) ($contact->name ?? '')))[0] ?? ''),
                    'email'             => (string) ($contact->email ?? ''),
                    // Event variables ({{invoice_url}}, {{order_number}}, …) so an
                    // order-placed flow can send THIS order's invoice. Empty for
                    // keyword/tag/group triggers — harmless.
                    'variables'         => (object) $variables,
                ]);

            if (!$r->successful()) {
                Log::warning('[FLOW-ENROLL] launch — Node rejected', [
                    'flow_id' => $flow->id, 'contact_id' => $contact->id, 'engine' => $engine,
                    'sender' => $devicePhone, 'status' => $r->status(),
                ]);
                $sub->update([
                    'status'         => 'failed',
                    'failed_at'      => now(),
                    'failure_reason' => 'Node ' . $r->status() . ': ' . mb_substr((string) $r->body(), 0, 150),
                ]);
            } else {
                Log::info('[FLOW-ENROLL] launched OK', [
                    'flow_id' => $flow->id, 'contact_id' => $contact->id, 'engine' => $engine,
                    'sender' => $devicePhone, 'recipient' => $recipient, 'subscriber_id' => $sub->id,
                ]);
            }
        } catch (\Throwable $e) {
            $sub->update([
                'status'         => 'failed',
                'failed_at'      => now(),
                'failure_reason' => 'Node unreachable: ' . mb_substr($e->getMessage(), 0, 150),
            ]);
        }
    }

    /**
     * Run an Instagram flow over the IG bridge.
     *
     * Needs three things the WhatsApp path never carries, all passed in through
     * $variables by whoever enrolled the contact:
     *   __ig_igsid      the commenter / sender IGSID (this is the "phone")
     *   __ig_account_id which InstagramAccount to send as
     *   comment_id      set for comment→DM, so an `ig_reply_comment` node can
     *                   post its public reply. Empty for a plain DM trigger.
     *
     * Anything missing is recorded on the subscriber as a readable reason
     * instead of failing silently, which is how the original bug hid.
     */
    private function launchInstagramFlow(Contact $contact, Flow $flow, FlowSubscriber $sub, array $variables = []): void
    {
        $igsid     = (string) ($variables['__ig_igsid'] ?? '');
        $accountId = (int) ($variables['__ig_account_id'] ?? 0);
        $commentId = (string) ($variables['comment_id'] ?? '');

        // Every step below logs under [IG-FLOW] so a live issue can be traced
        // with one grep instead of guessing:
        //     tail -f storage/logs/laravel.log | grep IG-FLOW
        // This path used to fail silently, which is precisely why "I comment
        // and nothing happens" was so hard to pin down.
        $trace = [
            'flow_id'    => $flow->id,
            'contact_id' => $contact->id,
            'account_id' => $accountId,
            'igsid'      => $igsid,
            'comment_id' => $commentId !== '' ? $commentId : '(none — DM trigger)',
        ];
        Log::info('[IG-FLOW] launch requested', $trace);

        if ($igsid === '' || $accountId <= 0) {
            $reason = 'Instagram flow enrolled without an IGSID/account — the trigger did not pass one.';
            Log::warning('[IG-FLOW] ABORT — ' . $reason, $trace);
            $sub->update([
                'status'         => 'failed',
                'failed_at'      => now(),
                'failure_reason' => $reason,
            ]);

            return;
        }

        if (! class_exists(\App\Models\InstagramAccount::class)
            || ! class_exists(\App\Services\Instagram\IgFlowBridge::class)) {
            $reason = 'Instagram add-on is not installed on this deployment.';
            Log::error('[IG-FLOW] ABORT — ' . $reason, $trace);
            $sub->update([
                'status'         => 'failed',
                'failed_at'      => now(),
                'failure_reason' => $reason,
            ]);

            return;
        }

        $account = \App\Models\InstagramAccount::query()
            ->where('workspace_id', (int) $flow->workspace_id)
            ->whereKey($accountId)
            ->first();
        if (! $account) {
            // Very common after importing a flow exported from another install:
            // the trigger still names the OLD account id.
            $reason = 'Instagram account #' . $accountId . ' not found in workspace ' . $flow->workspace_id
                . ' — re-pick the account on the flow trigger.';
            Log::warning('[IG-FLOW] ABORT — ' . $reason, $trace);
            $sub->update([
                'status'         => 'failed',
                'failed_at'      => now(),
                'failure_reason' => $reason,
            ]);

            return;
        }

        // Strip the internal routing keys — a flow's own {{vars}} should not see
        // them — but keep comment_id, which nodes legitimately use.
        $vars = $variables;
        unset($vars['__ig_igsid'], $vars['__ig_account_id']);

        $ok = \App\Services\Instagram\IgFlowBridge::handoff(
            $account,
            $igsid,
            (string) ($variables['text'] ?? ''),
            $flow->decoded_flow_data,
            $flow->id,
            $vars,
            $commentId,
        );

        if ($ok) {
            Log::info('[IG-FLOW] handed to the Instagram bridge OK', $trace);
            $sub->update(['status' => 'active', 'failed_at' => null, 'failure_reason' => null]);

            return;
        }

        $reason = 'Instagram bridge did not accept the flow (is the Node server reachable?)';
        Log::error('[IG-FLOW] ABORT — ' . $reason, $trace + [
            'hint' => 'Check SERVER_URL / baileys_server_url points at the running Node process, and that Node has the IG flow service.',
        ]);
        $sub->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => $reason]);
    }

    private function resolveDevice(Flow $flow): ?Device
    {
        // trigger_device_id is POLYMORPHIC — it points at `devices` only when
        // the flow's provider is 'unofficial'. For waba/twilio it names a
        // wa_provider_configs row and for instagram an instagram_accounts row;
        // those ids overlap with real `devices` ids, so an unguarded
        // Device::find() would happily return a DIFFERENT, unrelated number.
        // NOTE the key is 'baileys' — "Unofficial API" is only the display
        // label. Every existing row in this table uses 'baileys'/'twilio'.
        if ($flow->trigger_device_id && in_array((string) $flow->provider, [\App\Services\WorkspaceEngine::ENGINE_BAILEYS, ''], true)) {
            $d = Device::find($flow->trigger_device_id);
            if ($d && $d->active && $d->workspace_id === $flow->workspace_id) return $d;
        }
        // Fallback: prefer a CONNECTED device, not merely an `active` one. An
        // active-but-logged-out device has NO live Baileys socket, so the flow's
        // send is accepted by Node but never delivered — the customer gets
        // nothing while the team inbox still shows the flow reply (the reported
        // "Unofficial API also" bug). The custom auto-reply works because it
        // sends over the conversation's own connected device. Fall back to the
        // old active-only pick only if NO device reports connected, so a
        // single-device install with a stale status column still runs.
        return Device::query()
                ->where('workspace_id', $flow->workspace_id)
                ->where('active', true)
                ->where('status', 'connected')
                ->orderBy('id')
                ->first()
            ?: Device::query()
                ->where('workspace_id', $flow->workspace_id)
                ->where('active', true)
                ->orderBy('id')
                ->first();
    }

    private function contactBelongsToWorkspace(Contact $contact, int $workspaceId): bool
    {
        if ($contact->workspace_id) {
            return (int) $contact->workspace_id === $workspaceId;
        }
        $contactUser = \App\Models\User::find($contact->user_id);
        if (!$contactUser) return false;
        if ($contactUser->current_workspace_id === $workspaceId) return true;
        return $contactUser->workspaces()->where('workspaces.id', $workspaceId)->exists();
    }
}
