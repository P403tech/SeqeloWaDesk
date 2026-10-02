<?php

namespace App\Services\Facebook;

use App\Models\Contact;
use App\Models\ContactCustomField;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\DealCustomField;
use App\Models\FacebookPage;
use App\Models\MetaLead;
use App\Models\MetaLeadForm;
use App\Models\Pipeline;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turns a Meta Instant-Form submission into a contact, a deal and an assignment.
 *
 * ORDER MATTERS. The lead row is written the moment the answers are fetched,
 * BEFORE any mapping or routing runs. If the contact merge or the deal creation
 * then fails, the lead is still here as `failed` and can be retried — instead of
 * being lost to Meta's ~90-day deletion window. Everything after the fetch is
 * best-effort and recorded, never fatal.
 *
 * SAFE TO RUN TWICE. `meta_leads.leadgen_id` is UNIQUE, so the realtime webhook
 * and the backfill sweep can both see the same lead and the second one no-ops.
 */
class MetaLeadIngestService
{
    /**
     * Realtime path: called by the `leadgen` webhook, which carries IDs only.
     *
     * @return array{ok: bool, status: string, lead_id?: int, reason?: string}
     */
    public function ingest(FacebookPage $page, string $leadgenId, string $formId = ''): array
    {
        $leadgenId = trim($leadgenId);
        if ($leadgenId === '') {
            return ['ok' => false, 'status' => 'skipped', 'reason' => 'no_leadgen_id'];
        }

        $wsId = (int) $page->workspace_id;

        // 1. DEDUPE FIRST — before any Graph call. A webhook retry must not cost
        //    a round-trip, and must never create a second contact or deal.
        $existing = MetaLead::where('leadgen_id', $leadgenId)->first();
        if ($existing && $existing->ingest_status === MetaLead::STATUS_OK) {
            return ['ok' => true, 'status' => 'duplicate', 'lead_id' => $existing->id];
        }

        // 2. FETCH the answers. The webhook carries IDs, never what was typed.
        $client = new FacebookPageClient($page);
        $raw    = $client->getLead($leadgenId);

        if (! $raw) {
            // Record the failure ON A ROW so it is retryable and visible in the
            // UI, rather than a log line nobody reads. A token without
            // leads_retrieval surfaces exactly here, which is where an operator
            // can actually act on it.
            $reason = $client->lastError ?: 'graph_fetch_failed';
            $lead   = $this->stubLead($wsId, $leadgenId, $formId, $existing);
            $lead->forceFill([
                'ingest_status' => MetaLead::STATUS_FAILED,
                'ingest_error'  => mb_substr($reason, 0, 255),
            ])->save();
            Log::warning('[META-LEAD] fetch failed', ['leadgen_id' => $leadgenId, 'reason' => $reason]);

            return ['ok' => false, 'status' => 'failed', 'lead_id' => $lead->id, 'reason' => $reason];
        }

        return $this->store($page, $raw, $formId, $existing, $client);
    }

    /**
     * Persist one ALREADY-FETCHED lead payload and route it.
     *
     * Shared with the backfill sweep, which reads whole pages of leads from
     * GET /{form}/leads and so never needs the per-lead fetch above.
     */
    public function store(
        FacebookPage $page,
        array $raw,
        string $formId = '',
        ?MetaLead $existing = null,
        ?FacebookPageClient $client = null,
    ): array {
        $wsId      = (int) $page->workspace_id;
        $leadgenId = trim((string) ($raw['id'] ?? ''));
        if ($leadgenId === '') {
            return ['ok' => false, 'status' => 'skipped', 'reason' => 'no_leadgen_id'];
        }

        $existing ??= MetaLead::where('leadgen_id', $leadgenId)->first();
        if ($existing && $existing->ingest_status === MetaLead::STATUS_OK) {
            return ['ok' => true, 'status' => 'duplicate', 'lead_id' => $existing->id];
        }

        $formId = (string) ($raw['form_id'] ?? '') ?: $formId;

        // Resolve the form config. An unknown form gets a DISABLED placeholder so
        // its leads are captured while the operator configures it — the
        // alternative is silently dropping real leads because nobody had opened
        // the settings screen yet.
        $form = $this->resolveForm($page, $formId, $client ?: new FacebookPageClient($page));

        // Store the lead with full attribution, still `pending`.
        $lead = $this->stubLead($wsId, $leadgenId, $formId, $existing);
        $lead->forceFill([
            'meta_lead_form_id' => $form?->id,
            'form_id'           => $formId ?: null,
            'ad_id'             => $this->str($raw, 'ad_id'),
            'ad_name'           => $this->str($raw, 'ad_name'),
            'adset_id'          => $this->str($raw, 'adset_id'),
            'adset_name'        => $this->str($raw, 'adset_name'),
            'campaign_id'       => $this->str($raw, 'campaign_id'),
            'campaign_name'     => $this->str($raw, 'campaign_name'),
            // fb | ig — the SAME Page-level webhook carries Instagram lead ads,
            // so this is the only thing separating the two placements.
            'platform'          => $this->str($raw, 'platform'),
            'is_organic'        => (bool) ($raw['is_organic'] ?? false),
            'field_data'        => (array) ($raw['field_data'] ?? []),
            'submitted_at'      => $this->parseTime($raw['created_time'] ?? null),
            'ingest_status'     => MetaLead::STATUS_PENDING,
            'ingest_error'      => null,
        ])->save();

        // Outbound webhook — fired HERE, once, the moment the answers are stored.
        // Not in route(): a lead can be re-routed by the Retry button, and a
        // subscriber must not be told the same person submitted twice. Duplicates
        // returned above before reaching this point, so webhook and backfill
        // seeing the same lead still produce exactly one event.
        $this->emitLeadWebhook($lead, $form);

        // A form nobody has configured: the lead is safely stored, but we do not
        // guess a pipeline or an owner on their behalf.
        if (! $form || ! $form->enabled) {
            $lead->forceFill([
                'ingest_status' => MetaLead::STATUS_PENDING,
                'ingest_error'  => $form ? 'form_disabled' : 'form_not_configured',
            ])->save();

            return ['ok' => true, 'status' => 'stored_unrouted', 'lead_id' => $lead->id];
        }

        return $this->route($lead, $form);
    }

    /**
     * Apply a configured form's routing to a stored lead. Split out from the
     * fetch so the UI's "Retry" button can re-run JUST this half without another
     * Graph call — the answers are already on the row.
     */
    public function route(MetaLead $lead, MetaLeadForm $form): array
    {
        $wsId = (int) $lead->workspace_id;

        try {
            [$contactFields, $contactCustom, $dealCustom] = $this->mapAnswers($form, $lead->answers());

            $contact = $this->mergeContact($wsId, $contactFields, $contactCustom);
            if (! $contact) {
                // Nothing to message and nothing to dedupe on. Kept as `failed`
                // so it stays visible and re-runnable once the mapping is fixed —
                // usually the phone question simply was never mapped.
                $lead->forceFill([
                    'ingest_status' => MetaLead::STATUS_FAILED,
                    'ingest_error'  => 'no phone or email in the mapped answers',
                ])->save();

                return ['ok' => false, 'status' => 'failed', 'lead_id' => $lead->id, 'reason' => 'no_identity'];
            }

            $this->applyTags($form, $contact);

            $deal = $form->create_deal
                ? $this->createOrUpdateDeal($form, $contact, $lead, $dealCustom)
                : null;

            $lead->forceFill([
                'contact_id'    => $contact->id,
                'deal_id'       => $deal?->id,
                'ingest_status' => MetaLead::STATUS_OK,
                'ingest_error'  => null,
            ])->save();

            $this->startWelcomeFlow($form, $contact, $lead);

            Log::info('[META-LEAD] ingested', [
                'lead' => $lead->id, 'contact' => $contact->id, 'deal' => $deal?->id,
                'campaign' => $lead->campaign_name, 'platform' => $lead->platform,
            ]);

            return ['ok' => true, 'status' => 'ok', 'lead_id' => $lead->id];
        } catch (\Throwable $e) {
            $lead->forceFill([
                'ingest_status' => MetaLead::STATUS_FAILED,
                'ingest_error'  => mb_substr($e->getMessage(), 0, 255),
            ])->save();
            Log::warning('[META-LEAD] route failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 'failed', 'lead_id' => $lead->id, 'reason' => $e->getMessage()];
        }
    }

    /* ── storage ─────────────────────────────────────────────────────────── */

    /** Find-or-make the row, so a failure always has somewhere to be recorded. */
    private function stubLead(int $wsId, string $leadgenId, string $formId, ?MetaLead $existing): MetaLead
    {
        return $existing ?: MetaLead::firstOrCreate(
            ['leadgen_id' => $leadgenId],
            ['workspace_id' => $wsId, 'form_id' => $formId ?: null, 'ingest_status' => MetaLead::STATUS_PENDING],
        );
    }

    /**
     * Resolve the form row, creating a DISABLED placeholder when Meta sends a
     * lead from a form this workspace has never configured. The questions are
     * pulled so the mapping screen has something to show, and a best-effort
     * auto-map is pre-filled so the obvious rows are already done.
     */
    private function resolveForm(FacebookPage $page, string $formId, FacebookPageClient $client): ?MetaLeadForm
    {
        if ($formId === '') {
            return null;
        }

        $form = MetaLeadForm::where('workspace_id', $page->workspace_id)
            ->where('form_id', $formId)
            ->first();
        if ($form) {
            return $form;
        }

        $meta = null;
        foreach ($client->getLeadForms() as $f) {
            if ((string) ($f['id'] ?? '') === $formId) {
                $meta = $f;
                break;
            }
        }
        $questions = (array) ($meta['questions'] ?? []);

        Log::info('[META-LEAD] discovered an unconfigured form', [
            'form_id' => $formId, 'page' => $page->id, 'name' => $meta['name'] ?? null,
        ]);

        // Disabled on purpose: capture the lead, but do not invent a pipeline,
        // an owner or a field mapping on the operator's behalf.
        return MetaLeadForm::create([
            'workspace_id'     => $page->workspace_id,
            'facebook_page_id' => $page->id,
            'form_id'          => $formId,
            'name'             => (string) ($meta['name'] ?? ('Form '.$formId)),
            'status'           => (string) ($meta['status'] ?? 'ACTIVE'),
            'locale'           => (string) ($meta['locale'] ?? '') ?: null,
            'questions'        => $questions,
            'field_map'        => MetaLeadForm::guessFieldMap($questions),
            'enabled'          => false,
        ]);
    }

    /* ── mapping ─────────────────────────────────────────────────────────── */

    /**
     * Split the answers into the three buckets the field map can target.
     *
     * An UNMAPPED answer is not dropped — it stays in the lead's field_data and
     * is shown on the lead row, so nothing the customer typed is ever lost.
     *
     * @return array{0: array, 1: array, 2: array}
     */
    private function mapAnswers(MetaLeadForm $form, array $answers): array
    {
        $map           = (array) ($form->field_map ?? []);
        $contactFields = [];
        $contactCustom = [];
        $dealCustom    = [];

        // Standard contact columns a lead may write. Never workspace_id/user_id/
        // mobile_hash — those are tenancy and index plumbing, not lead data.
        $allowedContact = [
            'first_name', 'middle_name', 'last_name', 'name', 'title',
            'email', 'mobile', 'country_code', 'language', 'address',
        ];

        foreach ($answers as $qKey => $value) {
            $rule = $map[$qKey] ?? null;
            if (! is_array($rule)) {
                continue;
            }

            $target = (string) ($rule['target'] ?? 'contact');
            $key    = trim((string) ($rule['key'] ?? ''));
            $value  = trim((string) $value);
            if ($key === '' || $value === '') {
                continue;
            }

            if ($target === 'contact' && in_array($key, $allowedContact, true)) {
                $contactFields[$key] = $value;
            } elseif ($target === 'contact_custom') {
                $contactCustom[$key] = $value;
            } elseif ($target === 'deal_custom') {
                $dealCustom[$key] = $value;
            }
        }

        return [$contactFields, $contactCustom, $dealCustom];
    }

    /**
     * Find the existing contact or create one.
     *
     * Matched on PHONE first, then email — a phone is the identity WhatsApp
     * actually messages on, and a household can share one email. Merging rather
     * than inserting is what stops the same person becoming five contacts after
     * five form submissions.
     */
    private function mergeContact(int $wsId, array $fields, array $custom): ?Contact
    {
        $phone = (string) ($fields['mobile'] ?? '');
        $email = mb_strtolower(trim((string) ($fields['email'] ?? '')));

        // A lead with neither is unusable: nothing to message, nothing to dedupe
        // on. The caller records that as `failed` rather than making a ghost row.
        if (preg_replace('/\D+/', '', $phone) === '' && $email === '') {
            return null;
        }

        $contact = $this->findByPhone($wsId, $phone, (string) ($fields['country_code'] ?? ''))
            ?: $this->findByEmail($wsId, $email);

        if (! $contact) {
            $contact               = new Contact();
            $contact->workspace_id = $wsId;
            $contact->user_id      = (int) (Workspace::whereKey($wsId)->value('owner_user_id') ?? 0) ?: null;
            $contact->channel      = 'facebook';
        }

        // Only fill BLANKS on an existing contact: a lead form should enrich a
        // known customer, never overwrite a name an operator corrected by hand.
        foreach ($fields as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if ($contact->exists && trim((string) $contact->{$k}) !== '') {
                continue;
            }
            $contact->{$k} = $v;
        }

        if ($custom) {
            $attrs = is_array($contact->custom_attributes) ? $contact->custom_attributes : [];
            $defs  = ContactCustomField::where('workspace_id', $wsId)->pluck('key')->all();
            foreach ($custom as $k => $v) {
                // An undefined field would be invisible in the UI, so it stays in
                // the lead's own field_data instead of becoming a dead attribute.
                if (! in_array((string) $k, $defs, true)) {
                    continue;
                }
                $attrs[(string) $k] = $v;
            }
            $contact->custom_attributes = $attrs;
        }

        $contact->save();

        return $contact;
    }

    /** Indexed lookup — `contacts.mobile` is encrypted, `mobile_hash` is not. */
    private function findByPhone(int $wsId, string $phone, string $cc): ?Contact
    {
        $hash = Contact::hashPhone($cc ?: null, $phone);
        if (! $hash) {
            return null;
        }

        return Contact::where('workspace_id', $wsId)->where('mobile_hash', $hash)->first();
    }

    /**
     * Email fallback. There is no email hash column, so this has to decrypt as it
     * goes — chunked rather than loaded whole, since it only runs for the
     * minority of forms that ask for an email and no phone.
     */
    private function findByEmail(int $wsId, string $email): ?Contact
    {
        if ($email === '') {
            return null;
        }

        $found = null;
        Contact::where('workspace_id', $wsId)
            ->whereNotNull('email')
            ->chunkById(500, function ($rows) use ($email, &$found) {
                foreach ($rows as $c) {
                    if (mb_strtolower(trim((string) $c->email)) === $email) {
                        $found = $c;

                        return false;
                    }
                }

                return true;
            });

        return $found;
    }

    private function applyTags(MetaLeadForm $form, Contact $contact): void
    {
        $ids = array_filter(array_map('intval', (array) ($form->tag_ids ?? [])));
        if (! $ids) {
            return;
        }

        try {
            // Scoped to the workspace so a stale id from another tenant cannot be
            // attached. syncWithoutDetaching keeps tags an operator added by hand.
            $valid = Tag::where('workspace_id', $form->workspace_id)->whereIn('id', $ids)->pluck('id')->all();
            if ($valid) {
                $contact->tags()->syncWithoutDetaching($valid);
            }
        } catch (\Throwable $e) {
            Log::warning('[META-LEAD] tagging failed: '.$e->getMessage());
        }
    }

    /* ── deal ────────────────────────────────────────────────────────────── */

    /**
     * Create the deal — unless this person already has an OPEN deal from THIS
     * form, in which case the submission is appended to that deal's timeline.
     *
     * Pipeline pollution is the standard failure of lead-ads CRMs: one person
     * re-submitting a form five times should not leave the sales team five open
     * duplicates to reconcile.
     */
    private function createOrUpdateDeal(MetaLeadForm $form, Contact $contact, MetaLead $lead, array $dealCustom): ?Deal
    {
        $wsId = (int) $form->workspace_id;

        $pipeline = $form->pipeline_id
            ? Pipeline::where('workspace_id', $wsId)->find($form->pipeline_id)
            : Pipeline::ensureDefaultForWorkspace($wsId);
        if (! $pipeline) {
            return null;
        }

        $stage = $form->stage_id ? $pipeline->stages()->find($form->stage_id) : null;
        $stage ??= $pipeline->stages()->orderBy('sort_order')->first();
        if (! $stage) {
            return null;
        }

        $existing = Deal::where('workspace_id', $wsId)
            ->where('contact_id', $contact->id)
            ->where('status', 'open')
            ->where('meta->lead_form_id', (string) $form->form_id)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            try {
                DealActivity::create([
                    'deal_id'      => $existing->id,
                    'workspace_id' => $wsId,
                    'type'         => 'note',
                    'body'         => 'Submitted "'.$form->name.'" again'
                        .($lead->campaign_name ? ' via '.$lead->campaign_name : '').".\n"
                        .$this->answersAsText($lead),
                    'meta'         => ['source' => 'meta_lead', 'leadgen_id' => (string) $lead->leadgen_id],
                ]);
            } catch (\Throwable $e) {
                Log::warning('[META-LEAD] repeat-submission note failed: '.$e->getMessage());
            }

            // Refresh the custom fields from the newer answers — the latest
            // submission is the better information.
            if ($dealCustom) {
                $this->applyDealCustom($existing, $dealCustom, $wsId);
            }

            return $existing;
        }

        $deal = new Deal([
            'workspace_id'  => $wsId,
            'pipeline_id'   => $pipeline->id,
            'stage_id'      => $stage->id,
            'contact_id'    => $contact->id,
            'title'         => trim($form->name.' — '.($contact->name ?: 'New lead')),
            'value_minor'   => 0,
            'currency'      => $pipeline->currency,
            'owner_user_id' => $this->pickOwner($form),
            'owner_team_id' => $form->owner_team_id ?: null,
            'source'        => 'form',
            'meta'          => [
                'lead_form_id' => (string) $form->form_id,
                'leadgen_id'   => (string) $lead->leadgen_id,
                // Attribution stamped onto the deal itself, so a sales report can
                // answer "which campaign produced our won revenue?" without
                // joining back through the lead.
                'meta_ad'      => array_filter([
                    'ad_id'         => $lead->ad_id,
                    'ad_name'       => $lead->ad_name,
                    'adset_id'      => $lead->adset_id,
                    'adset_name'    => $lead->adset_name,
                    'campaign_id'   => $lead->campaign_id,
                    'campaign_name' => $lead->campaign_name,
                    'platform'      => $lead->platform,
                    'is_organic'    => $lead->is_organic,
                ], fn ($v) => $v !== null && $v !== ''),
            ],
        ]);
        // Deal::created() fires deal_created — and deal_assigned when an owner is
        // set — so a lead can start a CRM flow with no extra wiring here.
        $deal->save();

        if ($dealCustom) {
            $this->applyDealCustom($deal, $dealCustom, $wsId);
        }

        return $deal;
    }

    /**
     * Write mapped deal custom fields into deals.meta->custom, the same address
     * the deal drawer reads. Undefined or ill-typed values are skipped, never
     * guessed — one bad answer must not wipe a good stored value.
     */
    private function applyDealCustom(Deal $deal, array $values, int $wsId): void
    {
        try {
            $defs   = DealCustomField::where('workspace_id', $wsId)->get()->keyBy('key');
            $meta   = is_array($deal->meta) ? $deal->meta : [];
            $custom = is_array($meta['custom'] ?? null) ? $meta['custom'] : [];

            foreach ($values as $k => $v) {
                $def = $defs->get((string) $k);
                if (! $def) {
                    continue;
                }
                $coerced = $def->coerce(trim((string) $v));
                if ($coerced === false) {
                    continue;
                }
                $custom[(string) $k] = $coerced;
            }

            $meta['custom'] = $custom;
            $deal->forceFill(['meta' => $meta])->save();
        } catch (\Throwable $e) {
            Log::warning('[META-LEAD] deal custom fields failed: '.$e->getMessage());
        }
    }

    /**
     * Resolve the deal owner. `fixed` uses the configured user; `round_robin`
     * rotates across the team's members, or all workspace members when no team
     * is set.
     */
    private function pickOwner(MetaLeadForm $form): ?int
    {
        $fixed = $form->owner_user_id ? (int) $form->owner_user_id : null;
        if ($form->assign_strategy !== 'round_robin') {
            return $fixed;
        }

        try {
            $memberIds = $form->owner_team_id
                ? \App\Models\Team::whereKey($form->owner_team_id)->first()?->members()->pluck('users.id')->all()
                : Workspace::whereKey($form->workspace_id)->first()?->members()->pluck('users.id')->all();
            $memberIds = array_values(array_filter((array) $memberIds));
            if (! $memberIds) {
                return $fixed;
            }

            // Rotate on the count of deals this form has already produced, so the
            // spread stays even without needing a cursor column.
            $seen = (int) Deal::where('workspace_id', $form->workspace_id)
                ->where('meta->lead_form_id', (string) $form->form_id)
                ->count();

            return (int) $memberIds[$seen % count($memberIds)];
        } catch (\Throwable $e) {
            Log::warning('[META-LEAD] round-robin failed: '.$e->getMessage());

            return $fixed;
        }
    }

    /** Optional welcome flow — enrol the new contact if the form names one. */
    private function startWelcomeFlow(MetaLeadForm $form, Contact $contact, MetaLead $lead): void
    {
        if (! $form->flow_id) {
            return;
        }

        try {
            $flow = \App\Models\Flow::where('workspace_id', $form->workspace_id)->find($form->flow_id);
            if (! $flow) {
                return;
            }

            app(\App\Services\Flow\FlowEnrollmentService::class)->enroll($contact, $flow, array_filter([
                'form_name'     => (string) $form->name,
                'campaign_name' => (string) ($lead->campaign_name ?? ''),
                'ad_name'       => (string) ($lead->ad_name ?? ''),
            ], fn ($v) => $v !== ''));
        } catch (\Throwable $e) {
            Log::warning('[META-LEAD] welcome flow failed: '.$e->getMessage());
        }
    }

    /* ── small helpers ───────────────────────────────────────────────────── */

    /** The customer's answers as readable lines for a deal-timeline note. */
    private function answersAsText(MetaLead $lead): string
    {
        $lines = [];
        foreach ($lead->answers() as $k => $v) {
            if ($v === '') {
                continue;
            }
            $lines[] = str_replace('_', ' ', $k).': '.$v;
        }

        return implode("\n", $lines);
    }

    /**
     * Tell subscribed integrations a lead arrived, with the customer's answers
     * flattened to question → value (Meta's nested field_data[] shape is awkward
     * for a receiving CRM to walk) plus the full ad attribution.
     */
    private function emitLeadWebhook(MetaLead $lead, ?MetaLeadForm $form): void
    {
        try {
            \App\Services\WebhookService::emit('lead_received', array_filter([
                'workspace_id'  => (int) $lead->workspace_id,
                'lead_id'       => (int) $lead->id,
                'leadgen_id'    => (string) $lead->leadgen_id,
                'form_id'       => (string) ($lead->form_id ?? ''),
                'form_name'     => $form?->name,
                'answers'       => $lead->answers(),
                'campaign_id'   => $lead->campaign_id,
                'campaign_name' => $lead->campaign_name,
                'adset_id'      => $lead->adset_id,
                'adset_name'    => $lead->adset_name,
                'ad_id'         => $lead->ad_id,
                'ad_name'       => $lead->ad_name,
                // fb | ig — the same Page webhook carries both placements.
                'platform'      => $lead->platform,
                'is_organic'    => (bool) $lead->is_organic,
                'submitted_at'  => optional($lead->submitted_at)->toIso8601String(),
                'timestamp'     => now()->timestamp,
            ], fn ($v) => $v !== null && $v !== ''));
        } catch (\Throwable $e) {
            Log::warning('[META-LEAD] webhook emit failed: '.$e->getMessage());
        }
    }

    private function str(array $raw, string $key): ?string
    {
        $v = trim((string) ($raw[$key] ?? ''));

        return $v === '' ? null : mb_substr($v, 0, 191);
    }

    /** Meta sends ISO-8601 with an offset; a bad value must not lose the lead. */
    private function parseTime($value): Carbon
    {
        if (! $value) {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return now();
        }
    }
}
