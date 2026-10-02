<?php

namespace App\Services\Facebook;

use App\Models\FacebookPage;
use App\Models\MetaLead;
use App\Models\MetaLeadForm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps meta_lead_forms in step with Meta, and sweeps up leads the realtime
 * webhook never delivered.
 *
 * The sweep is not optional polish. Meta deletes leads roughly 90 days after
 * submission, and a webhook is a single delivery attempt against whatever the
 * app's URL was at the time — a deploy, an expired token or a Page that was
 * subscribed late all lose leads permanently with no second chance. This is
 * that second chance.
 *
 * Project policy is NO `schedule:run`, so the sweep runs INLINE off the leads
 * page (see MetaLeadsController), cache-gated per workspace so a page refresh
 * cannot hammer the Graph API.
 */
class MetaLeadSyncService
{
    /** How long a workspace-wide sweep stays "already done" for. */
    private const SWEEP_TTL = 600;   // 10 minutes

    public function __construct(private MetaLeadIngestService $ingest)
    {
    }

    /**
     * Pull the Page's Instant Forms into meta_lead_forms.
     *
     * REFRESHES the Meta-owned columns only. `field_map`, `enabled`, the
     * pipeline, the owner and the tags are the operator's configuration and are
     * never touched here — a re-sync must not silently undo their routing.
     *
     * @return array{ok: bool, synced: int, created: int, error?: string}
     */
    public function syncForms(FacebookPage $page): array
    {
        $client = new FacebookPageClient($page);
        $forms  = $client->getLeadForms();

        if (! $forms) {
            // No forms and no error is a legitimate answer — a Page can simply
            // have none. Only a real Graph error is worth reporting up.
            $err = $client->lastError;
            if ($err) {
                Log::warning('[META-LEAD] form sync failed', ['page' => $page->id, 'error' => $err]);

                return ['ok' => false, 'synced' => 0, 'created' => 0, 'error' => $err];
            }

            return ['ok' => true, 'synced' => 0, 'created' => 0];
        }

        $synced = $created = 0;

        foreach ($forms as $f) {
            $formId = (string) ($f['id'] ?? '');
            if ($formId === '') {
                continue;
            }

            $row = MetaLeadForm::where('workspace_id', $page->workspace_id)
                ->where('form_id', $formId)
                ->first();

            $questions = (array) ($f['questions'] ?? []);
            $meta      = [
                'facebook_page_id' => $page->id,
                'name'             => (string) ($f['name'] ?? ('Form '.$formId)),
                'status'           => (string) ($f['status'] ?? 'ACTIVE'),
                'locale'           => (string) ($f['locale'] ?? '') ?: null,
                'questions'        => $questions,
            ];

            if ($row) {
                $row->forceFill($meta)->save();
                $synced++;
                continue;
            }

            // New to us. Disabled until the operator picks a pipeline and an
            // owner — the auto-map only saves them the obvious rows.
            MetaLeadForm::create($meta + [
                'workspace_id' => $page->workspace_id,
                'form_id'      => $formId,
                'field_map'    => MetaLeadForm::guessFieldMap($questions),
                'enabled'      => false,
            ]);
            $created++;
            $synced++;
        }

        return ['ok' => true, 'synced' => $synced, 'created' => $created];
    }

    /**
     * Pull recent leads for ONE form and ingest anything the webhook missed.
     *
     * Incremental: asks Meta only for submissions newer than the last successful
     * sweep, falling back to a $days window the first time. Every lead still
     * goes through the same ingest path, and `leadgen_id` being unique is what
     * makes overlapping with the webhook harmless.
     *
     * @return array{ok: bool, seen: int, new: int, error?: string}
     */
    public function backfillForm(MetaLeadForm $form, int $days = 7): array
    {
        $page = $form->page;
        if (! $page || ! $page->exists) {
            return ['ok' => false, 'seen' => 0, 'new' => 0, 'error' => 'page_missing'];
        }

        $since = $form->last_synced_at
            ? $form->last_synced_at->copy()->subHours(2)   // overlap, so a lead that landed mid-sweep is not skipped
            : now()->subDays($days);

        $client = new FacebookPageClient($page);
        $leads  = $client->getFormLeads($form->form_id, [
            'limit'     => 100,
            'filtering' => [[
                'field'    => 'time_created',
                'operator' => 'GREATER_THAN',
                'value'    => $since->timestamp,
            ]],
        ]);

        if (! $leads && $client->lastError) {
            Log::warning('[META-LEAD] backfill failed', ['form' => $form->id, 'error' => $client->lastError]);

            return ['ok' => false, 'seen' => 0, 'new' => 0, 'error' => $client->lastError];
        }

        $seen = $new = 0;
        foreach ($leads as $raw) {
            $seen++;
            $leadgenId = (string) ($raw['id'] ?? '');
            if ($leadgenId === '') {
                continue;
            }

            // Cheap pre-check so the common case (webhook already handled it)
            // costs one indexed lookup and no writes.
            if (MetaLead::where('leadgen_id', $leadgenId)->where('ingest_status', MetaLead::STATUS_OK)->exists()) {
                continue;
            }

            $res = $this->ingest->store($page, $raw, $form->form_id, null, $client);
            if (($res['status'] ?? '') !== 'duplicate') {
                $new++;
            }
        }

        // Stamped only on a clean pass. A failed sweep must not advance the
        // cursor past leads it never actually read.
        $form->forceFill(['last_synced_at' => now()])->save();

        return ['ok' => true, 'seen' => $seen, 'new' => $new];
    }

    /**
     * Sweep every enabled form in a workspace. Cache-gated, because this is
     * called from a page load and a refreshing operator must not turn into a
     * Graph API flood.
     *
     * @return array{ran: bool, forms: int, new: int}
     */
    public function sweepWorkspace(int $workspaceId, bool $force = false): array
    {
        $key = 'meta_lead_sweep:'.$workspaceId;
        if (! $force && Cache::has($key)) {
            return ['ran' => false, 'forms' => 0, 'new' => 0];
        }
        Cache::put($key, 1, self::SWEEP_TTL);

        $forms = MetaLeadForm::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->with('page')
            ->get();

        $new = 0;
        foreach ($forms as $form) {
            try {
                $res = $this->backfillForm($form);
                $new += (int) ($res['new'] ?? 0);
            } catch (\Throwable $e) {
                // One broken form (revoked token, deleted Page) must not stop
                // the rest of the workspace from being swept.
                Log::warning('[META-LEAD] sweep failed for form', ['form' => $form->id, 'error' => $e->getMessage()]);
            }
        }

        return ['ran' => true, 'forms' => $forms->count(), 'new' => $new];
    }
}
