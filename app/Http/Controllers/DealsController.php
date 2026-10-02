<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sales Pipeline — Kanban board of deals (opportunities) a workspace chases.
 * Plan-gated via plan:access_sales_pipeline (route middleware) + the
 * config/plan_gates.php paywall overlay.
 */
class DealsController extends Controller
{
    /** GET /deals — the Kanban board. */
    public function index(Request $request)
    {
        $wsId = (int) ($request->user()->current_workspace_id ?? 0);

        // First visit seeds a default pipeline + the 6-stage ladder.
        $pipelines = Pipeline::forCurrentWorkspace()->orderBy('sort_order')->orderBy('id')->get();
        if ($pipelines->isEmpty()) {
            Pipeline::ensureDefaultForWorkspace($wsId);
            $pipelines = Pipeline::forCurrentWorkspace()->orderBy('sort_order')->orderBy('id')->get();
        }

        // Selected pipeline: ?pipeline= → default → first.
        $pipelineId = (int) $request->query('pipeline', 0);
        $pipeline   = $pipelines->firstWhere('id', $pipelineId)
            ?: $pipelines->firstWhere('is_default', true)
            ?: $pipelines->first();

        // Filters.
        $ownerId = (int) $request->query('owner', 0);
        $source  = (string) $request->query('source', '');
        $search  = trim((string) $request->query('q', ''));

        $stages = $pipeline->stages()
            ->with(['deals' => function ($q) use ($ownerId, $source, $search) {
                $q->with(['contact:id,name,first_name,last_name,country_code,mobile', 'owner:id,name'])
                  ->orderBy('sort_order')
                  ->orderByDesc('id');
                if ($ownerId) $q->where('owner_user_id', $ownerId);
                if ($source !== '') $q->where('source', $source);
                if ($search !== '') $q->where('title', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%');
            }])
            ->orderBy('sort_order')
            ->get();

        // Display currency follows the WORKSPACE setting (what the user picked),
        // falling back to the platform default — NOT the pipeline's stored code.
        // EVERY money figure on this page (cards, stage totals, KPIs) is converted
        // to it, so the board never mixes symbols (was: $ totals over ₹/SAR cards)
        // and mixed-currency deals sum correctly (not 100 USD + 100 INR = 200).
        $ws              = $request->user()->currentWorkspace;
        $members         = optional($ws)->members()->get(['users.id', 'users.name']) ?? collect();
        $displayCurrency = (string) (optional($ws)->currency
            ?: \App\Models\SystemSetting::get('default_currency', 'USD'));

        // Convert a deal's stored minor amount (in its OWN currency) → the display
        // currency, using the admin exchange rates (Admin → Currencies).
        $conv = function ($minor, $from) use ($displayCurrency): int {
            return (int) round(\App\Support\FormatSettings::convert(((int) $minor) / 100, $from ?: $displayCurrency, $displayCurrency) * 100);
        };

        // Per-stage + board rollups (weighted forecast = value × probability).
        // Each deal is converted first, and we stash the converted amount on the
        // model so the card renders in the SAME display currency as the totals.
        $columns = $stages->map(function (PipelineStage $s) use ($conv) {
            $deals = $s->deals;
            $deals->each(function ($d) use ($conv) {
                $d->display_minor = $conv($d->value_minor, $d->currency);
            });
            $valueMinor = (int) $deals->sum('display_minor');
            $weighted   = (int) round($valueMinor * $s->probability / 100);
            return [
                'stage'          => $s,
                'deals'          => $deals,
                'count'          => $deals->count(),
                'value_minor'    => $valueMinor,
                'weighted_minor' => $weighted,
            ];
        });

        $scope = fn () => Deal::forCurrentWorkspace()->where('pipeline_id', $pipeline->id);

        // KPIs convert each deal to the display currency before summing (can't be
        // done in SQL since each row may be a different currency).
        $openValueMinor = (int) $scope()->open()->get(['value_minor', 'currency'])
            ->sum(fn ($d) => $conv($d->value_minor, $d->currency));
        $forecastMinor  = (int) $columns->sum('weighted_minor');
        $openCount      = (int) $scope()->open()->count();
        $wonThisMonth   = (int) $scope()->where('status', 'won')->where('won_at', '>=', now()->startOfMonth())->count();
        $wonMonthValue  = (int) $scope()->where('status', 'won')->where('won_at', '>=', now()->startOfMonth())
            ->get(['value_minor', 'currency'])->sum(fn ($d) => $conv($d->value_minor, $d->currency));
        $wonAll         = (int) $scope()->where('status', 'won')->count();
        $lostAll        = (int) $scope()->where('status', 'lost')->count();
        $winRate        = ($wonAll + $lostAll) > 0 ? (int) round($wonAll / ($wonAll + $lostAll) * 100) : 0;

        return view('user.deals.index', [
            'pipelines'      => $pipelines,
            'pipeline'       => $pipeline,
            'columns'        => $columns,
            'members'        => $members,
            'sources'        => Deal::SOURCES,
            'currency'       => $displayCurrency,
            'kpis'           => [
                'open_count'     => $openCount,
                'open_value'     => $this->money($openValueMinor, $displayCurrency),
                'forecast'       => $this->money($forecastMinor, $displayCurrency),
                'won_this_month' => $wonThisMonth,
                'won_value'      => $this->money($wonMonthValue, $displayCurrency),
                'win_rate'       => $winRate,
            ],
            'filters'        => ['owner' => $ownerId, 'source' => $source, 'q' => $search],
            'wsSettings'     => [
                'auto' => (bool) optional($ws)->deals_auto_from_orders,
                'min'  => optional($ws)->deals_auto_min_minor !== null ? (int) $ws->deals_auto_min_minor / 100 : null,
            ],
        ]);
    }

    /** POST /deals/settings — save the per-workspace auto-deal-from-orders prefs. */
    public function saveSettings(Request $request): JsonResponse
    {
        $ws = $request->user()->currentWorkspace;
        if (!$ws) {
            return response()->json(['ok' => false, 'message' => 'No active workspace.'], 422);
        }
        $data = $request->validate([
            'auto_from_orders' => 'nullable|boolean',
            'min_value'        => 'nullable|numeric|min:0|max:99999999',
        ]);
        $ws->update([
            'deals_auto_from_orders' => (bool) ($data['auto_from_orders'] ?? false),
            'deals_auto_min_minor'   => (isset($data['min_value']) && $data['min_value'] !== null && $data['min_value'] !== '')
                ? (int) round((float) $data['min_value'] * 100)
                : null,
        ]);
        return response()->json(['ok' => true, 'message' => 'Settings saved.']);
    }

    /** POST /deals — quick-create a deal (lands in the first stage). */
    public function store(Request $request): JsonResponse
    {
        $wsId = (int) ($request->user()->current_workspace_id ?? 0);

        $data = $request->validate([
            'title'         => 'required|string|max:191',
            'pipeline_id'   => 'required|integer',
            'stage_id'      => 'nullable|integer',
            'value'         => 'nullable|numeric|min:0|max:99999999',
            'currency'      => 'nullable|string|max:10',
            'owner_user_id' => 'nullable|integer',
            'contact_id'    => 'nullable|integer',
            'expected_close_date' => 'nullable|date',
        ]);

        $pipeline = Pipeline::forCurrentWorkspace()->find((int) $data['pipeline_id']);
        if (!$pipeline) {
            return response()->json(['ok' => false, 'message' => 'Pipeline not found.'], 404);
        }

        // Target stage: requested (must belong to this pipeline) → first stage.
        $stage = null;
        if (!empty($data['stage_id'])) {
            $stage = $pipeline->stages()->find((int) $data['stage_id']);
        }
        $stage = $stage ?: $pipeline->stages()->orderBy('sort_order')->first();
        if (!$stage) {
            return response()->json(['ok' => false, 'message' => 'This pipeline has no stages.'], 422);
        }

        // Validate the linked contact / owner belong to the workspace.
        $contactId = null;
        if (!empty($data['contact_id'])) {
            $contactId = optional(Contact::forCurrentWorkspace()->find((int) $data['contact_id']))->id;
        }
        $ownerId = (int) ($data['owner_user_id'] ?? 0) ?: (int) $request->user()->id;

        $deal = Deal::create([
            'workspace_id'        => $wsId,
            'pipeline_id'         => $pipeline->id,
            'stage_id'            => $stage->id,
            'contact_id'          => $contactId,
            'title'               => $data['title'],
            'value_minor'         => (int) round((float) ($data['value'] ?? 0) * 100),
            'currency'            => $data['currency'] ?? $pipeline->currency,
            'owner_user_id'       => $ownerId,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'source'              => 'manual',
            'sort_order'          => 0,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Deal created.',
            'deal_id' => $deal->id,
        ]);
    }

    /** PATCH /deals/{deal}/stage — drag-drop a card to a new column. */
    public function updateStage(Request $request, int $deal): JsonResponse
    {
        $row = Deal::forCurrentWorkspace()->find($deal);
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Deal not found.'], 404);
        }

        $data = $request->validate([
            'stage_id'   => 'required|integer',
            'sort_order' => 'nullable|integer',
        ]);

        // The destination stage MUST belong to the deal's pipeline + workspace.
        $stage = PipelineStage::forCurrentWorkspace()
            ->where('pipeline_id', $row->pipeline_id)
            ->find((int) $data['stage_id']);
        if (!$stage) {
            return response()->json(['ok' => false, 'message' => 'Invalid target stage.'], 422);
        }

        // The model's saving() hook syncs status / won_at / lost_at; the
        // updated() hook logs the stage_change activity.
        $row->update([
            'stage_id'   => $stage->id,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return response()->json([
            'ok'      => true,
            'status'  => $row->status,
            'stage'   => ['id' => $stage->id, 'name' => $stage->name, 'is_won' => $stage->is_won, 'is_lost' => $stage->is_lost],
        ]);
    }

    /** GET /deals/reports — pipeline analytics (value by stage, win-rate, forecast, leaderboard). */
    public function reports(Request $request)
    {
        $wsId = (int) ($request->user()->current_workspace_id ?? 0);
        Pipeline::ensureDefaultForWorkspace($wsId);

        // Pipeline value + weighted forecast by stage (open deals).
        $stages  = PipelineStage::forCurrentWorkspace()->orderBy('sort_order')->get();
        $byStage = $stages->map(function (PipelineStage $s) {
            $minor = (int) Deal::forCurrentWorkspace()->where('stage_id', $s->id)->open()->sum('value_minor');
            $count = (int) Deal::forCurrentWorkspace()->where('stage_id', $s->id)->open()->count();
            return [
                'name'     => $s->name,
                'color'    => $s->color,
                'value'    => $minor / 100,
                'count'    => $count,
                'weighted' => round($minor * $s->probability / 100) / 100,
            ];
        })->values();

        $won  = (int) Deal::forCurrentWorkspace()->where('status', 'won')->count();
        $lost = (int) Deal::forCurrentWorkspace()->where('status', 'lost')->count();

        // Won vs lost over the last 6 months.
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $months[] = [
                'label' => $m->format('M'),
                'won'   => (int) Deal::forCurrentWorkspace()->where('status', 'won')->whereBetween('won_at', [$m, $m->copy()->endOfMonth()])->count(),
                'lost'  => (int) Deal::forCurrentWorkspace()->where('status', 'lost')->whereBetween('lost_at', [$m, $m->copy()->endOfMonth()])->count(),
            ];
        }

        // Per-agent leaderboard (won deals by value).
        $leaders = Deal::forCurrentWorkspace()
            ->where('status', 'won')->whereNotNull('owner_user_id')
            ->selectRaw('owner_user_id, COUNT(*) as won_count, SUM(value_minor) as won_minor')
            ->groupBy('owner_user_id')->orderByDesc('won_minor')->limit(10)->get()
            ->map(function ($r) {
                $u = \App\Models\User::find($r->owner_user_id);
                return ['name' => $u?->name ?? 'Unknown', 'won' => (int) $r->won_count, 'value' => (int) $r->won_minor / 100];
            })->values();

        // Why deals are lost. `lost_reason` was captured all along but never
        // reported on, so the field was write-only — an operator could see one
        // deal's reason on its card and never the pattern across the pipeline.
        // Grouped in PHP (not SQL) so an empty reason folds into one bucket and
        // casing differences on legacy free-text rows collapse together.
        $lostReasons = Deal::forCurrentWorkspace()->where('status', 'lost')
            ->get(['lost_reason', 'value_minor'])
            ->groupBy(fn ($d) => mb_strtolower(trim((string) $d->lost_reason)) ?: '__none__')
            ->map(fn ($rows) => [
                'name'  => trim((string) $rows->first()->lost_reason) ?: __('No reason given'),
                'count' => $rows->count(),
                'value' => (int) $rows->sum('value_minor') / 100,
            ])
            ->sortByDesc('count')->values();

        // Display currency = workspace setting → platform default (dynamic),
        // never the pipeline's stored code.
        $currency = (string) (optional(request()->user()->currentWorkspace)->currency
            ?: \App\Models\SystemSetting::get('default_currency', 'USD'));

        return view('user.deals.reports', [
            'byStage'  => $byStage,
            'lostReasons' => $lostReasons,
            'winRate'  => ($won + $lost) > 0 ? (int) round($won / ($won + $lost) * 100) : 0,
            'won'      => $won,
            'lost'     => $lost,
            'openValue'=> $this->money((int) Deal::forCurrentWorkspace()->open()->sum('value_minor'), $currency),
            'forecast' => $this->money((int) round($byStage->sum('weighted') * 100), $currency),
            'wonValue' => $this->money((int) Deal::forCurrentWorkspace()->where('status', 'won')->sum('value_minor'), $currency),
            'months'   => $months,
            'leaders'  => $leaders,
            'currency' => $currency,
            'symbol'   => $symbol = \App\Models\Currency::symbolFor($currency),
            // Pre-encoded blob for the charts JS — passed as one var so the
            // blade can do @json($report) instead of a multi-line array literal
            // (which Blade's @json directive can't parse).
            'report'   => ['byStage' => $byStage, 'months' => $months, 'symbol' => $symbol],
        ]);
    }

    /** GET /deals/{deal} — full detail (JSON) for the slide-over panel. */
    public function show(Request $request, int $deal): JsonResponse
    {
        $row = Deal::forCurrentWorkspace()
            ->with(['contact', 'owner', 'stage', 'pipeline', 'activities.user'])
            ->find($deal);
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Deal not found.'], 404);
        }

        $stages  = $row->pipeline ? $row->pipeline->stages()->orderBy('sort_order')->get(['id', 'name', 'is_won', 'is_lost']) : collect();
        $members = optional($request->user()->currentWorkspace)->members()->get(['users.id', 'users.name']) ?? collect();

        return response()->json([
            'ok'         => true,
            'deal'       => $this->serializeDeal($row),
            'stages'     => $stages,
            'members'    => $members,
            // Definitions joined to this deal's stored values, so the drawer can
            // render the inputs without a second round-trip. Empty array when
            // the workspace has defined no custom fields — the panel then omits
            // the section entirely rather than showing an empty heading.
            'custom'     => $this->dealCustomValues($row),
            'activities' => $row->activities->map(fn ($a) => $this->serializeActivity($a, $stages))->values(),
        ]);
    }

    /** PATCH /deals/{deal} — edit fields (title, value, owner, stage, contact, close date). */
    public function update(Request $request, int $deal): JsonResponse
    {
        $row = Deal::forCurrentWorkspace()->find($deal);
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Deal not found.'], 404);
        }

        $data = $request->validate([
            'title'               => 'sometimes|required|string|max:191',
            'value'               => 'sometimes|nullable|numeric|min:0|max:99999999',
            'currency'            => 'sometimes|nullable|string|max:10',
            'owner_user_id'       => 'sometimes|nullable|integer',
            'stage_id'            => 'sometimes|nullable|integer',
            'contact_id'          => 'sometimes|nullable|integer',
            'expected_close_date' => 'sometimes|nullable|date',
            // Workspace-defined custom fields, as { key: value }. Merged (never
            // replaced) into meta->custom so a partial submit can't erase fields
            // the drawer didn't render.
            'custom'              => 'sometimes|array|max:60',
            'custom.*'            => 'nullable|string|max:2000',
        ]);

        $patch = [];
        $skippedCustom = [];
        if (array_key_exists('custom', $data)) {
            [$patch['meta'], $skippedCustom] = $this->mergeDealCustom($row, (array) $data['custom']);
        }
        if (array_key_exists('title', $data))    $patch['title'] = $data['title'];
        if (array_key_exists('value', $data))    $patch['value_minor'] = (int) round((float) ($data['value'] ?? 0) * 100);
        if (array_key_exists('currency', $data) && $data['currency']) $patch['currency'] = $data['currency'];
        if (array_key_exists('expected_close_date', $data)) $patch['expected_close_date'] = $data['expected_close_date'];

        if (array_key_exists('owner_user_id', $data)) {
            $patch['owner_user_id'] = $data['owner_user_id'] ? (int) $data['owner_user_id'] : null;
        }
        if (array_key_exists('contact_id', $data)) {
            $patch['contact_id'] = $data['contact_id']
                ? optional(Contact::forCurrentWorkspace()->find((int) $data['contact_id']))->id
                : null;
        }
        // Stage change validated against the deal's own pipeline.
        if (!empty($data['stage_id'])) {
            $stage = PipelineStage::forCurrentWorkspace()->where('pipeline_id', $row->pipeline_id)->find((int) $data['stage_id']);
            if ($stage) $patch['stage_id'] = $stage->id;
        }

        $row->update($patch);

        return response()->json([
            'ok'     => true,
            'deal'   => $this->serializeDeal($row->fresh(['contact', 'owner', 'stage'])),
            'custom' => $this->dealCustomValues($row->fresh()),
            // Named so the drawer can say WHICH entry was rejected rather than
            // silently discarding it — an unexplained blank field reads as a bug.
            'skipped_custom' => $skippedCustom,
        ]);
    }

    /** DELETE /deals/{deal}. */
    public function destroy(Request $request, int $deal): JsonResponse
    {
        $row = Deal::forCurrentWorkspace()->find($deal);
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Deal not found.'], 404);
        }
        $row->activities()->delete();
        $row->delete();
        return response()->json(['ok' => true]);
    }

    /** POST /deals/{deal}/activity — add a note / task / logged call or message. */
    public function addActivity(Request $request, int $deal): JsonResponse
    {
        $row = Deal::forCurrentWorkspace()->find($deal);
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Deal not found.'], 404);
        }

        $data = $request->validate([
            'type'   => 'required|in:note,call,message,task',
            'body'   => 'required|string|max:5000',
            'due_at' => 'nullable|date',
        ]);

        $activity = $row->activities()->create([
            'workspace_id' => $row->workspace_id,
            'user_id'      => $request->user()->id,
            'type'         => $data['type'],
            'body'         => $data['body'],
            'due_at'       => $data['type'] === 'task' ? ($data['due_at'] ?? null) : null,
        ]);

        $stages = $row->pipeline ? $row->pipeline->stages()->get(['id', 'name', 'is_won', 'is_lost']) : collect();
        return response()->json(['ok' => true, 'activity' => $this->serializeActivity($activity->load('user'), $stages)]);
    }

    /** POST /deals/{deal}/task/{activity}/done — tick a task complete (or re-open). */
    public function completeTask(Request $request, int $deal, int $activity): JsonResponse
    {
        $row = Deal::forCurrentWorkspace()->find($deal);
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Deal not found.'], 404);
        }
        $task = $row->activities()->where('type', 'task')->find($activity);
        if (!$task) {
            return response()->json(['ok' => false, 'message' => 'Task not found.'], 404);
        }
        $done = !$task->done_at;
        $task->update(['done_at' => $done ? now() : null]);
        return response()->json(['ok' => true, 'done' => $done]);
    }

    /** POST /deals/{deal}/won — move to the pipeline's Won stage. */
    public function markWon(Request $request, int $deal): JsonResponse
    {
        return $this->markOutcome($request, $deal, 'is_won');
    }

    /** POST /deals/{deal}/lost — move to the Lost stage (+ optional reason). */
    public function markLost(Request $request, int $deal): JsonResponse
    {
        return $this->markOutcome($request, $deal, 'is_lost');
    }

    private function markOutcome(Request $request, int $deal, string $flag): JsonResponse
    {
        $row = Deal::forCurrentWorkspace()->find($deal);
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Deal not found.'], 404);
        }
        $stage = $row->pipeline?->stages()->where($flag, true)->orderBy('sort_order')->first();
        if (!$stage) {
            return response()->json(['ok' => false, 'message' => 'This pipeline has no ' . ($flag === 'is_won' ? 'Won' : 'Lost') . ' stage.'], 422);
        }
        if ($flag === 'is_lost') {
            $reason = trim((string) $request->input('reason', ''));
            // When the pipeline defines a picklist, the reason must come FROM it
            // — that is the whole point of configuring one, and reports group on
            // the exact string. Matched case-insensitively but stored in the
            // pipeline's own casing so the grouping stays clean. A pipeline with
            // no list keeps the original free-text behaviour untouched.
            $allowed = array_values((array) (optional($row->pipeline)->lost_reasons ?? []));
            if ($allowed && $reason !== '') {
                $hit = null;
                foreach ($allowed as $a) {
                    if (mb_strtolower(trim((string) $a)) === mb_strtolower($reason)) { $hit = (string) $a; break; }
                }
                if ($hit === null) {
                    return response()->json([
                        'ok'      => false,
                        'reasons' => $allowed,
                        'message' => __('Pick one of the lost reasons configured for this pipeline.'),
                    ], 422);
                }
                $reason = $hit;
            }
            if ($reason !== '') $row->lost_reason = mb_substr($reason, 0, 191);
        }
        $row->stage_id = $stage->id; // observer flips status + stamps timestamp
        $row->save();

        return response()->json(['ok' => true, 'status' => $row->status, 'stage' => ['id' => $stage->id, 'name' => $stage->name]]);
    }

    /** GET /deals/contacts/search?q= — link-a-contact picker. */
    public function contactsSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $rows = Contact::forCurrentWorkspace()
            ->when($q !== '', function ($w) use ($q) {
                $esc = str_replace(['%', '_'], ['\%', '\_'], $q);
                $w->where(function ($x) use ($esc) {
                    $x->where('name', 'like', "%{$esc}%")
                      ->orWhere('mobile', 'like', "%{$esc}%");
                });
            })
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'name', 'first_name', 'last_name', 'country_code', 'mobile']);

        return response()->json([
            'ok'   => true,
            'data' => $rows->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => $c->name ?: trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: mask_phone(Contact::canonicalizePhone($c->country_code, $c->mobile)),
                'phone' => mask_phone(Contact::canonicalizePhone($c->country_code, $c->mobile)),
            ])->values(),
        ]);
    }

    /** GET /deals/stages — default pipeline's stages (for the flow-builder deal_stage_changed picker). */
    public function stagesJson(Request $request): JsonResponse
    {
        $wsId = (int) ($request->user()->current_workspace_id ?? 0);
        $pipeline = Pipeline::ensureDefaultForWorkspace($wsId);
        return response()->json([
            'ok'   => true,
            'data' => $pipeline->stages()->orderBy('sort_order')->get(['id', 'name'])->all(),
        ]);
    }

    /* ==================================================================
     * Pipeline + stage management
     *
     * The tables always supported multiple pipelines and fully custom stages —
     * there was simply no way to reach them: one pipeline was auto-seeded from
     * Pipeline::DEFAULT_STAGES and nothing could add, rename, recolour, reorder
     * or delete anything. These endpoints expose what the schema already had.
     *
     * Two rules run through all of it:
     *   1. Nothing may orphan a deal. Deleting a pipeline or a stage that holds
     *      deals is refused unless the caller names a destination (`move_to`).
     *   2. A pipeline must keep exactly one Won stage and one Lost stage —
     *      markWon()/markLost() resolve the target by those flags, so removing
     *      the last one would break "Mark won" with a 422 the operator can't
     *      diagnose from the board.
     * ================================================================== */

    /** GET /deals/pipelines — id + name list, for pickers (board, flow builder). */
    public function pipelinesJson(Request $request): JsonResponse
    {
        $wsId = (int) ($request->user()->current_workspace_id ?? 0);
        Pipeline::ensureDefaultForWorkspace($wsId);

        $rows = Pipeline::forCurrentWorkspace()->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'currency', 'is_default', 'lost_reasons'])
            ->map(fn (Pipeline $p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'currency'     => $p->currency,
                'is_default'   => (bool) $p->is_default,
                'lost_reasons' => array_values((array) ($p->lost_reasons ?? [])),
                'stage_count'  => $p->stages()->count(),
                'deal_count'   => $p->deals()->count(),
            ])->values();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /** POST /deals/pipelines — create a board and seed the default stage ladder. */
    public function pipelineStore(Request $request): JsonResponse
    {
        $wsId = (int) ($request->user()->current_workspace_id ?? 0);
        if ($wsId <= 0) {
            return response()->json(['ok' => false, 'message' => __('No active workspace.')], 422);
        }

        $data = $request->validate([
            'name'     => 'required|string|max:120',
            'currency' => 'nullable|string|max:10',
        ]);

        // Fall back to the workspace currency, never a hardcoded code — a
        // hardcoded 'INR' default is exactly what made the board convert every
        // amount into the wrong currency before (see ensureDefaultForWorkspace).
        $currency = strtoupper(trim((string) ($data['currency'] ?? ''))) ?:
            (string) (optional($request->user()->currentWorkspace)->currency
                ?: \App\Models\SystemSetting::get('default_currency', 'USD'));

        $isFirst = Pipeline::forCurrentWorkspace()->count() === 0;

        $pipeline = Pipeline::create([
            'workspace_id' => $wsId,
            'name'         => trim($data['name']),
            'is_default'   => $isFirst,   // a workspace's first board is its default
            'currency'     => $currency,
            'sort_order'   => (int) Pipeline::forCurrentWorkspace()->max('sort_order') + 1,
        ]);
        $pipeline->seedDefaultStages();

        \App\Support\Audit::log('deals.pipeline_created', [
            'subject_type' => 'pipeline', 'subject_id' => $pipeline->id,
            'meta' => ['name' => $pipeline->name],
        ]);

        return response()->json([
            'ok'       => true,
            'message'  => __('Pipeline created.'),
            'pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name],
        ]);
    }

    /** PATCH /deals/pipelines/{id} — rename, currency, default flag, lost reasons. */
    public function pipelineUpdate(Request $request, int $id): JsonResponse
    {
        $pipeline = Pipeline::forCurrentWorkspace()->find($id);
        if (! $pipeline) {
            return response()->json(['ok' => false, 'message' => __('Pipeline not found.')], 404);
        }

        $data = $request->validate([
            'name'           => 'sometimes|required|string|max:120',
            'currency'       => 'sometimes|nullable|string|max:10',
            'is_default'     => 'sometimes|boolean',
            'lost_reasons'   => 'sometimes|array|max:40',
            'lost_reasons.*' => 'string|max:120',
        ]);

        $patch = [];
        if (array_key_exists('name', $data))     $patch['name'] = trim($data['name']);
        if (array_key_exists('currency', $data) && $data['currency']) {
            $patch['currency'] = strtoupper(trim($data['currency']));
        }
        if (array_key_exists('lost_reasons', $data)) {
            // Trim, drop blanks, de-duplicate case-insensitively — the whole
            // point of the list is that reports can GROUP by it, which a list
            // holding both "Price" and "price " would defeat.
            $seen = [];
            $clean = [];
            foreach ($data['lost_reasons'] as $r) {
                $r = trim((string) $r);
                if ($r === '') continue;
                $k = mb_strtolower($r);
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $clean[]  = $r;
            }
            $patch['lost_reasons'] = $clean;
        }
        if ($patch) $pipeline->update($patch);

        // Promotion is its own transactional step (demote the others first).
        // Un-setting a default is NOT supported: a workspace must always have
        // exactly one, so the way to change it is to promote a different board.
        if (! empty($data['is_default'])) {
            $pipeline->setAsDefault();
        }

        return response()->json(['ok' => true, 'message' => __('Pipeline saved.')]);
    }

    /**
     * DELETE /deals/pipelines/{id}[?move_to=<pipelineId>]
     *
     * Refuses to strand deals. With `move_to` the deals are remapped onto the
     * destination's FIRST stage and the board is removed; without it, a pipeline
     * holding deals is a 422 that names the count so the operator can decide.
     */
    public function pipelineDestroy(Request $request, int $id): JsonResponse
    {
        $pipeline = Pipeline::forCurrentWorkspace()->find($id);
        if (! $pipeline) {
            return response()->json(['ok' => false, 'message' => __('Pipeline not found.')], 404);
        }
        if (Pipeline::forCurrentWorkspace()->count() <= 1) {
            return response()->json(['ok' => false, 'message' =>
                __('This is your only pipeline — create another one before deleting this.')], 422);
        }

        $dealCount = (int) Deal::forCurrentWorkspace()->where('pipeline_id', $pipeline->id)->count();
        $moveTo    = (int) $request->query('move_to', 0);

        if ($dealCount > 0 && $moveTo <= 0) {
            return response()->json([
                'ok'         => false,
                'needs_move' => true,
                'deal_count' => $dealCount,
                'message'    => trans_choice(
                    '{1}This pipeline still holds :count deal. Choose where to move it first.'
                    . '|[2,*]This pipeline still holds :count deals. Choose where to move them first.',
                    $dealCount, ['count' => $dealCount]
                ),
            ], 422);
        }

        $target = null;
        if ($dealCount > 0) {
            $target = Pipeline::forCurrentWorkspace()->where('id', '!=', $pipeline->id)->find($moveTo);
            if (! $target) {
                return response()->json(['ok' => false, 'message' => __('Destination pipeline not found.')], 422);
            }
            if ($target->stages()->count() === 0) $target->seedDefaultStages();
        }

        $wasDefault = (bool) $pipeline->is_default;

        \Illuminate\Support\Facades\DB::transaction(function () use ($pipeline, $target, $dealCount) {
            if ($dealCount > 0 && $target) {
                $firstStage = $target->stages()->orderBy('sort_order')->first();
                // Bulk query update on purpose: this bypasses the Deal observer,
                // so remapping 400 deals during an admin cleanup does NOT fire 400
                // stage-change flow enrolments and blast the customers. A tidy-up
                // is not a sales event.
                Deal::where('workspace_id', $pipeline->workspace_id)
                    ->where('pipeline_id', $pipeline->id)
                    ->update(['pipeline_id' => $target->id, 'stage_id' => $firstStage->id]);
            }
            $pipeline->stages()->delete();
            $pipeline->delete();
        });

        // Never leave the workspace without a default — index() resolves the
        // board by that flag and would otherwise fall through to "first by id".
        if ($wasDefault) {
            Pipeline::forCurrentWorkspace()->orderBy('sort_order')->orderBy('id')->first()?->setAsDefault();
        }

        \App\Support\Audit::log('deals.pipeline_deleted', [
            'subject_type' => 'pipeline', 'subject_id' => $id,
            'meta' => ['moved_deals' => $dealCount, 'moved_to' => $target?->id],
        ]);

        return response()->json(['ok' => true, 'message' => $dealCount > 0
            ? trans_choice('{1}Pipeline deleted — :count deal moved.|[2,*]Pipeline deleted — :count deals moved.', $dealCount, ['count' => $dealCount])
            : __('Pipeline deleted.')]);
    }

    /** POST /deals/pipelines/{id}/stages — append a stage to the ladder. */
    public function stageStore(Request $request, int $id): JsonResponse
    {
        $pipeline = Pipeline::forCurrentWorkspace()->find($id);
        if (! $pipeline) {
            return response()->json(['ok' => false, 'message' => __('Pipeline not found.')], 404);
        }

        $data = $this->validateStage($request, true);

        // A stage can be Won or Lost, never both — the Deal observer reads the
        // flags to decide the deal's status and would have to pick arbitrarily.
        if (! empty($data['is_won']))  $data['is_lost'] = false;
        if (! empty($data['is_lost'])) $data['is_won']  = false;

        $stage = $pipeline->stages()->create([
            'workspace_id' => $pipeline->workspace_id,
            'name'         => trim($data['name']),
            'color'        => $data['color'] ?? '#64748B',
            'probability'  => (int) ($data['probability'] ?? 0),
            'is_won'       => (bool) ($data['is_won'] ?? false),
            'is_lost'      => (bool) ($data['is_lost'] ?? false),
            'sort_order'   => (int) $pipeline->stages()->max('sort_order') + 1,
        ]);

        // Promoting a new terminal stage demotes the old one, so the pipeline
        // still resolves exactly one Won and one Lost target.
        $this->enforceSingleTerminal($pipeline, $stage);

        return response()->json(['ok' => true, 'message' => __('Stage added.'), 'stage' => $stage->fresh()]);
    }

    /** PATCH /deals/stages/{id} — rename, recolour, probability, Won/Lost flags. */
    public function stageUpdate(Request $request, int $id): JsonResponse
    {
        $stage = PipelineStage::forCurrentWorkspace()->find($id);
        if (! $stage) {
            return response()->json(['ok' => false, 'message' => __('Stage not found.')], 404);
        }

        $data  = $this->validateStage($request, false);
        $patch = [];
        if (array_key_exists('name', $data))        $patch['name']        = trim($data['name']);
        if (array_key_exists('color', $data))       $patch['color']       = $data['color'];
        if (array_key_exists('probability', $data)) $patch['probability'] = (int) $data['probability'];

        if (array_key_exists('is_won', $data)) {
            $patch['is_won'] = (bool) $data['is_won'];
            if ($patch['is_won']) $patch['is_lost'] = false;
        }
        if (array_key_exists('is_lost', $data)) {
            $patch['is_lost'] = (bool) $data['is_lost'];
            if ($patch['is_lost']) $patch['is_won'] = false;
        }

        // Clearing the flag on the pipeline's ONLY Won (or Lost) stage would
        // leave markWon()/markLost() with nothing to resolve — they 422 with
        // "this pipeline has no Won stage", which reads as a bug from the board.
        foreach (['is_won' => __('Won'), 'is_lost' => __('Lost')] as $flag => $label) {
            if (array_key_exists($flag, $patch) && $patch[$flag] === false && $stage->{$flag}) {
                $others = PipelineStage::forCurrentWorkspace()
                    ->where('pipeline_id', $stage->pipeline_id)
                    ->where('id', '!=', $stage->id)->where($flag, true)->count();
                if ($others === 0) {
                    return response()->json(['ok' => false, 'message' => __(
                        'This is the only :label stage on the pipeline. Mark another stage as :label first.',
                        ['label' => $label]
                    )], 422);
                }
            }
        }

        $stage->update($patch);
        $this->enforceSingleTerminal($stage->pipeline, $stage->fresh());

        return response()->json(['ok' => true, 'message' => __('Stage saved.'), 'stage' => $stage->fresh()]);
    }

    /** POST /deals/pipelines/{id}/stages/reorder — rewrite sort_order from ids[]. */
    public function stageReorder(Request $request, int $id): JsonResponse
    {
        $pipeline = Pipeline::forCurrentWorkspace()->find($id);
        if (! $pipeline) {
            return response()->json(['ok' => false, 'message' => __('Pipeline not found.')], 404);
        }

        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        // Only ids that really belong to THIS pipeline are honoured — the board
        // posts what it rendered, and a stale tab could otherwise renumber a
        // stage that has since moved to another board.
        $owned = $pipeline->stages()->pluck('id')->all();
        $order = array_values(array_filter(array_map('intval', $data['ids']), fn ($i) => in_array($i, $owned, true)));
        if (! $order) {
            return response()->json(['ok' => false, 'message' => __('Nothing to reorder.')], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $pipeline, $owned) {
            foreach ($order as $i => $stageId) {
                PipelineStage::where('id', $stageId)->where('pipeline_id', $pipeline->id)
                    ->update(['sort_order' => $i]);
            }
            // Anything the client left out keeps a stable position AFTER the
            // ordered block, so a partial post can never collapse two stages
            // onto the same sort_order.
            $rest = array_values(array_diff($owned, $order));
            foreach ($rest as $j => $stageId) {
                PipelineStage::where('id', $stageId)->where('pipeline_id', $pipeline->id)
                    ->update(['sort_order' => count($order) + $j]);
            }
        });

        return response()->json(['ok' => true, 'message' => __('Stages reordered.')]);
    }

    /** DELETE /deals/stages/{id}[?move_to=<stageId>] — remove a column. */
    public function stageDestroy(Request $request, int $id): JsonResponse
    {
        $stage = PipelineStage::forCurrentWorkspace()->find($id);
        if (! $stage) {
            return response()->json(['ok' => false, 'message' => __('Stage not found.')], 404);
        }

        $siblings = PipelineStage::forCurrentWorkspace()->where('pipeline_id', $stage->pipeline_id);
        if ((clone $siblings)->count() <= 1) {
            return response()->json(['ok' => false, 'message' =>
                __('A pipeline needs at least one stage.')], 422);
        }

        foreach (['is_won' => __('Won'), 'is_lost' => __('Lost')] as $flag => $label) {
            if ($stage->{$flag} && (clone $siblings)->where('id', '!=', $stage->id)->where($flag, true)->count() === 0) {
                return response()->json(['ok' => false, 'message' => __(
                    'This is the only :label stage on the pipeline. Mark another stage as :label before deleting it.',
                    ['label' => $label]
                )], 422);
            }
        }

        $dealCount = (int) Deal::forCurrentWorkspace()->where('stage_id', $stage->id)->count();
        $moveTo    = (int) $request->query('move_to', 0);

        if ($dealCount > 0 && $moveTo <= 0) {
            return response()->json([
                'ok'         => false,
                'needs_move' => true,
                'deal_count' => $dealCount,
                'message'    => trans_choice(
                    '{1}This stage still holds :count deal. Choose where to move it first.'
                    . '|[2,*]This stage still holds :count deals. Choose where to move them first.',
                    $dealCount, ['count' => $dealCount]
                ),
            ], 422);
        }

        $target = null;
        if ($dealCount > 0) {
            $target = (clone $siblings)->where('id', '!=', $stage->id)->find($moveTo);
            if (! $target) {
                return response()->json(['ok' => false, 'message' =>
                    __('Pick a destination stage on the same pipeline.')], 422);
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($stage, $target, $dealCount) {
            if ($dealCount > 0 && $target) {
                // Bulk update, observer bypassed — same reasoning as the pipeline
                // remap above: housekeeping must not fire stage-change automations.
                Deal::where('workspace_id', $stage->workspace_id)
                    ->where('stage_id', $stage->id)
                    ->update(['stage_id' => $target->id]);
            }
            $stage->delete();
        });

        return response()->json(['ok' => true, 'message' => $dealCount > 0
            ? trans_choice('{1}Stage deleted — :count deal moved.|[2,*]Stage deleted — :count deals moved.', $dealCount, ['count' => $dealCount])
            : __('Stage deleted.')]);
    }

    /** Shared validation for stageStore (required name) + stageUpdate (partial). */
    private function validateStage(Request $request, bool $creating): array
    {
        $rule = $creating ? 'required' : 'sometimes|required';

        return $request->validate([
            'name'        => $rule . '|string|max:120',
            // Hex only — the value is written straight into a style attribute
            // on the board, so anything else is both broken and an injection
            // vector on a field the operator controls.
            'color'       => 'sometimes|nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'probability' => 'sometimes|nullable|integer|min:0|max:100',
            'is_won'      => 'sometimes|boolean',
            'is_lost'     => 'sometimes|boolean',
        ]);
    }

    /**
     * Keep at most one Won and one Lost stage per pipeline. `$keep` is the stage
     * just created or edited — every other row carrying the same flag is cleared.
     */
    private function enforceSingleTerminal(Pipeline $pipeline, PipelineStage $keep): void
    {
        foreach (['is_won', 'is_lost'] as $flag) {
            if (! $keep->{$flag}) continue;
            PipelineStage::where('pipeline_id', $pipeline->id)
                ->where('id', '!=', $keep->id)
                ->where($flag, true)
                ->update([$flag => false]);
        }
    }

    /* ==================================================================
     * Deal custom fields
     *
     * Definitions live in `deal_custom_fields`; VALUES live in the existing
     * (previously unused) deals.meta->custom map, keyed by the field's `key`.
     * Contacts have had this since the inbox was built — deals never did, so
     * anything a workspace tracked beyond title/value/owner went into the free
     * -text notes box where nothing could filter or report on it.
     * ================================================================== */

    /** GET /deals/fields — the workspace's deal custom-field definitions. */
    public function fieldsIndex(Request $request): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => \App\Models\DealCustomField::forCurrentWorkspace()
                ->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($f) => [
                    'id'            => $f->id,
                    'key'           => $f->key,
                    'label'         => $f->label,
                    'type'          => $f->type,
                    'options'       => array_values((array) ($f->options ?? [])),
                    'required'      => (bool) $f->required,
                    'show_in_panel' => (bool) $f->show_in_panel,
                    'sort'          => (int) $f->sort,
                ])->values(),
        ]);
    }

    /** POST /deals/fields — define a new custom field. */
    public function fieldStore(Request $request): JsonResponse
    {
        $wsId = (int) ($request->user()->current_workspace_id ?? 0);
        if ($wsId <= 0) {
            return response()->json(['ok' => false, 'message' => __('No active workspace.')], 422);
        }

        $data = $this->validateFieldDefinition($request, true);

        // The key IS the storage address inside deals.meta->custom, so it has to
        // be a stable slug — not whatever the operator typed. Derived from the
        // label when omitted, and never editable afterwards (see fieldUpdate).
        $key = \Illuminate\Support\Str::slug((string) ($data['key'] ?? $data['label']), '_');
        $key = mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower($key)), 0, 64);
        if ($key === '') {
            return response()->json(['ok' => false, 'message' => __('Give the field a name using letters or numbers.')], 422);
        }
        if (\App\Models\DealCustomField::where('workspace_id', $wsId)->where('key', $key)->exists()) {
            return response()->json(['ok' => false, 'message' => __('A field with that name already exists.')], 422);
        }

        $field = \App\Models\DealCustomField::create([
            'workspace_id'  => $wsId,
            'key'           => $key,
            'label'         => trim($data['label']),
            'type'          => $data['type'] ?? 'text',
            'options'       => $this->cleanFieldOptions($data['options'] ?? []),
            'required'      => (bool) ($data['required'] ?? false),
            'show_in_panel' => (bool) ($data['show_in_panel'] ?? true),
            'sort'          => (int) \App\Models\DealCustomField::forCurrentWorkspace()->max('sort') + 1,
        ]);

        return response()->json(['ok' => true, 'message' => __('Field added.'), 'field' => $field]);
    }

    /** PATCH /deals/fields/{id} — edit a definition. The KEY is immutable. */
    public function fieldUpdate(Request $request, int $id): JsonResponse
    {
        $field = \App\Models\DealCustomField::forCurrentWorkspace()->find($id);
        if (! $field) {
            return response()->json(['ok' => false, 'message' => __('Field not found.')], 404);
        }

        $data  = $this->validateFieldDefinition($request, false);
        $patch = [];
        if (array_key_exists('label', $data))         $patch['label']         = trim($data['label']);
        if (array_key_exists('type', $data))          $patch['type']          = $data['type'];
        if (array_key_exists('options', $data))       $patch['options']       = $this->cleanFieldOptions($data['options']);
        if (array_key_exists('required', $data))      $patch['required']      = (bool) $data['required'];
        if (array_key_exists('show_in_panel', $data)) $patch['show_in_panel'] = (bool) $data['show_in_panel'];
        if (array_key_exists('sort', $data))          $patch['sort']          = (int) $data['sort'];

        // `key` is deliberately NOT patchable: every stored value is addressed
        // by it, so renaming would orphan the data on every existing deal.
        // Renaming the LABEL is what an operator actually wants, and that is
        // free — the label is display-only.
        $field->update($patch);

        return response()->json(['ok' => true, 'message' => __('Field saved.'), 'field' => $field->fresh()]);
    }

    /**
     * DELETE /deals/fields/{id} — remove a definition.
     *
     * Stored values are LEFT ALONE in deals.meta->custom. Sweeping them would
     * mean rewriting every deal in the workspace to undo one mis-click, and
     * re-creating the field with the same key brings the data straight back.
     */
    public function fieldDestroy(int $id): JsonResponse
    {
        $field = \App\Models\DealCustomField::forCurrentWorkspace()->find($id);
        if (! $field) {
            return response()->json(['ok' => false, 'message' => __('Field not found.')], 404);
        }
        $field->delete();

        return response()->json(['ok' => true, 'message' => __('Field removed. Values already saved on deals are kept.')]);
    }

    /** Shared validation for fieldStore (label required) + fieldUpdate (partial). */
    private function validateFieldDefinition(Request $request, bool $creating): array
    {
        $rule = $creating ? 'required' : 'sometimes|required';

        return $request->validate([
            'label'         => $rule . '|string|max:128',
            'key'           => 'sometimes|nullable|string|max:64',
            'type'          => 'sometimes|string|in:' . implode(',', \App\Models\DealCustomField::TYPES),
            'options'       => 'sometimes|array|max:60',
            'options.*'     => 'string|max:120',
            'required'      => 'sometimes|boolean',
            'show_in_panel' => 'sometimes|boolean',
            'sort'          => 'sometimes|integer|min:0|max:9999',
        ]);
    }

    /** Trim, drop blanks, de-duplicate select options case-insensitively. */
    private function cleanFieldOptions($options): array
    {
        $seen = [];
        $out  = [];
        foreach ((array) $options as $o) {
            $o = trim((string) $o);
            if ($o === '') continue;
            $k = mb_strtolower($o);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[]    = $o;
        }

        return $out;
    }

    /**
     * Merge submitted custom values into a deal's meta->custom map.
     *
     * Merge, never replace: a partial submit (the drawer sends only the fields
     * it rendered) must not erase values it never showed. A value that does not
     * fit its declared type is SKIPPED, keeping the old one — a bad entry must
     * not wipe good data, and one bad field must not fail the whole save.
     *
     * @return array{0: array, 1: array}  [new meta, skipped keys]
     */
    private function mergeDealCustom(Deal $deal, array $submitted): array
    {
        $defs    = \App\Models\DealCustomField::forCurrentWorkspace()->get()->keyBy('key');
        $meta    = is_array($deal->meta) ? $deal->meta : [];
        $custom  = is_array($meta['custom'] ?? null) ? $meta['custom'] : [];
        $skipped = [];

        foreach ($submitted as $key => $val) {
            $def = $defs->get((string) $key);
            if (! $def) { $skipped[] = (string) $key; continue; }

            $coerced = $def->coerce(trim((string) $val));
            if ($coerced === false) { $skipped[] = (string) $key; continue; }

            $custom[(string) $key] = $coerced;
        }

        $meta['custom'] = $custom;

        return [$meta, $skipped];
    }

    /** Resolve a deal's custom values for the UI, joined to their definitions. */
    private function dealCustomValues(Deal $deal): array
    {
        $meta   = is_array($deal->meta) ? $deal->meta : [];
        $custom = is_array($meta['custom'] ?? null) ? $meta['custom'] : [];

        return \App\Models\DealCustomField::forCurrentWorkspace()
            ->orderBy('sort')->orderBy('id')->get()
            ->map(fn ($f) => [
                'key'           => $f->key,
                'label'         => $f->label,
                'type'          => $f->type,
                'options'       => array_values((array) ($f->options ?? [])),
                'required'      => (bool) $f->required,
                'show_in_panel' => (bool) $f->show_in_panel,
                'value'         => $custom[$f->key] ?? null,
            ])->values()->all();
    }

    /* -------------------- serializers -------------------- */

    private function serializeDeal(Deal $d): array
    {
        $c = $d->contact;
        $hasContact = $c && $c->id;
        $rawPhone   = $hasContact ? Contact::canonicalizePhone($c->country_code, $c->mobile) : null;

        return [
            'id'            => $d->id,
            'title'         => $d->title,
            'value'         => $d->value_minor / 100,
            'value_display' => $d->value_display,
            'currency'      => $d->currency,
            'status'        => $d->status,
            'conversation_id' => $d->conversation_id, // deep-link "Open in inbox"
            'stage_id'      => $d->stage_id,
            'stage_name'    => optional($d->stage)->name,
            'owner_user_id' => $d->owner_user_id,
            'owner_name'    => $d->owner && $d->owner->id ? $d->owner->name : null,
            'source'        => $d->source,
            'lost_reason'   => $d->lost_reason,
            'expected_close_date' => optional($d->expected_close_date)->toDateString(),
            'created_at'    => optional($d->created_at)->toDayDateTimeString(),
            'contact'       => $hasContact ? [
                'id'        => $c->id,
                'name'      => $c->name ?: trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: mask_phone(Contact::canonicalizePhone($c->country_code, $c->mobile)),
                'phone'     => mask_phone(Contact::canonicalizePhone($c->country_code, $c->mobile)),
                'wa_phone'  => $rawPhone, // for the wa.me deep link only
            ] : null,
        ];
    }

    private function serializeActivity($a, $stages): array
    {
        $label = null;
        if ($a->type === 'stage_change' && is_array($a->meta)) {
            $from = $stages->firstWhere('id', (int) ($a->meta['from_stage_id'] ?? 0));
            $to   = $stages->firstWhere('id', (int) ($a->meta['to_stage_id'] ?? 0));
            $label = trim((optional($from)->name ?? '?') . ' → ' . (optional($to)->name ?? '?'));
        }

        return [
            'id'         => $a->id,
            'type'       => $a->type,
            'body'       => $a->body,
            'label'      => $label,
            'user_name'  => $a->user && $a->user->id ? $a->user->name : 'System',
            'due_at'     => optional($a->due_at)->toDayDateTimeString(),
            'done'       => (bool) $a->done_at,
            'created_at' => optional($a->created_at)->diffForHumans(),
        ];
    }

    /** Minor-units → display string (mirrors Deal::valueDisplay). */
    private function money(int $minor, string $currency): string
    {
        // Symbol resolves dynamically from the admin currencies table — one
        // source of truth, no hardcoded per-currency map.
        return \App\Models\Currency::symbolFor($currency) . number_format($minor / 100, 2);
    }
}
