<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\ContactCustomField;
use App\Models\DealCustomField;
use App\Models\FacebookPage;
use App\Models\Flow;
use App\Models\MetaLead;
use App\Models\MetaLeadForm;
use App\Models\Pipeline;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Workspace;
use App\Services\Facebook\MetaLeadIngestService;
use App\Services\Facebook\MetaLeadSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Meta Lead Ads — the Instant Forms a Page runs, and the leads they produce.
 *
 * The Graph half of this already existed on FacebookPageClient and was never
 * called; this is the operator's side of it: map each form's questions onto
 * contact/deal fields, choose where the deal lands and who owns it, then watch
 * the leads arrive.
 *
 * Every action is workspace-scoped through the models' own scopes — a form id
 * from another tenant resolves to nothing rather than to someone else's data.
 */
class MetaLeadsController extends Controller
{
    public function __construct(
        private MetaLeadSyncService $sync,
        private MetaLeadIngestService $ingest,
    ) {
    }

    public function index(Request $request): View
    {
        $wsId  = $this->wsId();
        $pages = FacebookPage::forWorkspace($wsId)->connected()->orderBy('name')->get();

        // Project policy is no cron, so the backfill sweep rides on this page
        // load. Cache-gated inside the service, so a refreshing operator cannot
        // turn into a Graph API flood.
        if ($pages->isNotEmpty()) {
            try {
                $this->sync->sweepWorkspace($wsId);
            } catch (\Throwable $e) {
                // A sweep failure must never blank the page the operator came for.
                report($e);
            }
        }

        $forms = MetaLeadForm::forCurrentWorkspace()
            ->with('page')
            ->withCount('leads')
            ->orderByDesc('id')
            ->get();

        $selected = null;
        if ($forms->isNotEmpty()) {
            $formId   = (int) $request->integer('form', $forms->first()->id);
            $selected = $forms->firstWhere('id', $formId) ?: $forms->first();
        }

        $leads = MetaLead::forCurrentWorkspace()
            ->when($selected, fn ($q) => $q->where('meta_lead_form_id', $selected->id))
            ->when($request->filled('status'), fn ($q) => $q->where('ingest_status', $request->string('status')))
            ->with(['contact', 'deal'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('user.lead-ads.index', [
            'pages'          => $pages,
            'forms'          => $forms,
            'selected'       => $selected,
            'leads'          => $leads,
            'kpis'           => $this->kpis($wsId),
            'pipelines'      => Pipeline::where('workspace_id', $wsId)->with('stages')->orderBy('sort_order')->get(),
            'members'        => Workspace::whereKey($wsId)->first()?->members()->orderBy('name')->get() ?? collect(),
            'teams'          => Team::where('workspace_id', $wsId)->orderBy('name')->get(),
            'tags'           => Tag::where('workspace_id', $wsId)->orderBy('name')->get(),
            // flows.flow_name is ENCRYPTED, so it can neither be selected as
            // `name` (no such column — this 500'd the page) nor SQL-ordered
            // (that would sort ciphertext). Hydrate, then sort the decrypted
            // values in PHP, exactly as every other flow picker does.
            'flows'          => Flow::where('workspace_id', $wsId)
                ->get(['id', 'flow_name'])
                ->sortBy(fn ($f) => mb_strtolower((string) $f->flow_name), SORT_NATURAL)
                ->values(),
            'contactFields'  => ContactCustomField::where('workspace_id', $wsId)->orderBy('sort')->get(['key', 'label']),
            'dealFields'     => DealCustomField::where('workspace_id', $wsId)->orderBy('sort')->get(['key', 'label']),
        ]);
    }

    /** Pull the Page's forms from Meta into meta_lead_forms. */
    public function syncForms(): RedirectResponse
    {
        $wsId  = $this->wsId();
        $pages = FacebookPage::forWorkspace($wsId)->connected()->get();
        if ($pages->isEmpty()) {
            return back()->with('error', __('Connect a Facebook Page first.'));
        }

        $synced = $created = 0;
        $error  = null;
        foreach ($pages as $page) {
            $res = $this->sync->syncForms($page);
            $synced  += (int) ($res['synced'] ?? 0);
            $created += (int) ($res['created'] ?? 0);
            $error ??= $res['error'] ?? null;
        }

        if ($synced === 0 && $error) {
            // The usual cause is a token without leads_retrieval / pages_manage_ads,
            // which Meta grants only after App Review — say so plainly instead of
            // showing "0 forms" and letting them hunt.
            return back()->with('error', __('Could not read your lead forms: :err', ['err' => $error]));
        }

        return back()->with('success', __(':n form(s) synced, :new new.', ['n' => $synced, 'new' => $created]));
    }

    /** Save one form's mapping + routing. */
    public function update(Request $request, int $id): RedirectResponse
    {
        $form = MetaLeadForm::forCurrentWorkspace()->findOrFail($id);

        $data = $request->validate([
            'enabled'         => 'sometimes|boolean',
            'create_deal'     => 'sometimes|boolean',
            'pipeline_id'     => 'nullable|integer',
            'stage_id'        => 'nullable|integer',
            'owner_user_id'   => 'nullable|integer',
            'owner_team_id'   => 'nullable|integer',
            'assign_strategy' => 'nullable|in:fixed,round_robin',
            'flow_id'         => 'nullable|integer',
            'tag_ids'         => 'sometimes|array|max:20',
            'tag_ids.*'       => 'integer',
            'map'             => 'sometimes|array|max:80',
        ]);

        $wsId = (int) $form->workspace_id;

        // Every id below is re-checked against THIS workspace. Validation only
        // proves the shape; it does not prove ownership.
        $pipelineId = $this->ownedId(Pipeline::where('workspace_id', $wsId), $data['pipeline_id'] ?? null);
        $stageId    = null;
        if ($pipelineId) {
            $stageId = $this->ownedId(
                Pipeline::whereKey($pipelineId)->first()?->stages() ?? Pipeline::query()->whereRaw('1=0'),
                $data['stage_id'] ?? null,
            );
        }

        $form->forceFill([
            'enabled'         => (bool) ($data['enabled'] ?? false),
            'create_deal'     => (bool) ($data['create_deal'] ?? false),
            'pipeline_id'     => $pipelineId,
            'stage_id'        => $stageId,
            'owner_user_id'   => $this->ownedId(Workspace::whereKey($wsId)->first()?->members() ?? null, $data['owner_user_id'] ?? null),
            'owner_team_id'   => $this->ownedId(Team::where('workspace_id', $wsId), $data['owner_team_id'] ?? null),
            'assign_strategy' => $data['assign_strategy'] ?? 'fixed',
            'flow_id'         => $this->ownedId(Flow::where('workspace_id', $wsId), $data['flow_id'] ?? null),
            'tag_ids'         => array_values(array_map('intval', (array) Tag::where('workspace_id', $wsId)
                ->whereIn('id', array_map('intval', (array) ($data['tag_ids'] ?? [])))
                ->pluck('id')->all())),
            'field_map'       => $this->cleanMap((array) ($data['map'] ?? []), $wsId),
        ])->save();

        return back()->with('success', __('Lead form settings saved.'));
    }

    /**
     * Re-run routing for one stored lead. No Graph call — the answers are
     * already on the row, which is the whole point of storing before routing.
     */
    public function retry(int $id): JsonResponse
    {
        $lead = MetaLead::forCurrentWorkspace()->findOrFail($id);
        $form = $lead->form;

        if (! $form) {
            return response()->json(['ok' => false, 'error' => __('This lead has no form configured yet.')], 422);
        }
        if (! $form->enabled) {
            return response()->json(['ok' => false, 'error' => __('Enable this form before retrying its leads.')], 422);
        }

        $res = $this->ingest->route($lead, $form);

        return response()->json([
            'ok'     => (bool) ($res['ok'] ?? false),
            'status' => $lead->fresh()?->ingest_status,
            'error'  => $res['reason'] ?? null,
        ], ($res['ok'] ?? false) ? 200 : 422);
    }

    /** Force a backfill for one form, ignoring the sweep's cache gate. */
    public function backfill(int $id): RedirectResponse
    {
        $form = MetaLeadForm::forCurrentWorkspace()->with('page')->findOrFail($id);
        $res  = $this->sync->backfillForm($form, 30);

        if (! ($res['ok'] ?? false)) {
            return back()->with('error', __('Backfill failed: :err', ['err' => $res['error'] ?? __('unknown error')]));
        }

        return back()->with('success', __('Checked :seen lead(s), imported :new.', [
            'seen' => $res['seen'] ?? 0, 'new' => $res['new'] ?? 0,
        ]));
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function kpis(int $wsId): array
    {
        $base = MetaLead::where('workspace_id', $wsId);

        return [
            'total'   => (clone $base)->count(),
            'week'    => (clone $base)->where('submitted_at', '>=', now()->subDays(7))->count(),
            'ok'      => (clone $base)->where('ingest_status', MetaLead::STATUS_OK)->count(),
            'failed'  => (clone $base)->whereIn('ingest_status', [MetaLead::STATUS_FAILED, MetaLead::STATUS_PENDING])->count(),
        ];
    }

    /**
     * Return the id only when it really belongs to the given scoped query.
     *
     * The key is qualified because some of these are relations (workspace
     * members is a belongsToMany), where a bare `id` is ambiguous across the
     * joined pivot.
     */
    private function ownedId($query, $id): ?int
    {
        $id = (int) $id;
        if ($id <= 0 || ! $query) {
            return null;
        }

        return $query->where($query->getModel()->getQualifiedKeyName(), $id)->exists() ? $id : null;
    }

    /**
     * Keep only mappings that point somewhere real: a whitelisted contact
     * column, or a custom field this workspace has actually defined. A mapping
     * to a column that does not exist would fail silently at ingest time, which
     * is the worst place to discover it.
     */
    private function cleanMap(array $map, int $wsId): array
    {
        $allowedContact = [
            'first_name', 'middle_name', 'last_name', 'name', 'title',
            'email', 'mobile', 'country_code', 'language', 'address',
        ];
        $contactKeys = ContactCustomField::where('workspace_id', $wsId)->pluck('key')->all();
        $dealKeys    = DealCustomField::where('workspace_id', $wsId)->pluck('key')->all();

        $out = [];
        foreach ($map as $question => $rule) {
            $question = trim((string) $question);
            $target   = (string) (is_array($rule) ? ($rule['target'] ?? '') : '');
            $key      = trim((string) (is_array($rule) ? ($rule['key'] ?? '') : ''));
            if ($question === '' || $key === '') {
                continue;   // "not mapped" is a legitimate choice
            }

            $ok = match ($target) {
                'contact'        => in_array($key, $allowedContact, true),
                'contact_custom' => in_array($key, $contactKeys, true),
                'deal_custom'    => in_array($key, $dealKeys, true),
                default          => false,
            };
            if ($ok) {
                $out[$question] = ['target' => $target, 'key' => $key];
            }
        }

        return $out;
    }
}
