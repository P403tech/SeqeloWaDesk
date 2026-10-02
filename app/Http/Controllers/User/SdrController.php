<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\LeadScoringRule;
use App\Models\SdrCampaign;
use App\Services\LeadScoring\LeadScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * AI SDR management: campaigns (cadence flow + qualification + routing + stop),
 * lead-scoring rules, and enrollment stats. The heavy lifting (scoring engine,
 * orchestrator, cadence flow engine) lives in services; this is the CRUD +
 * dashboard surface.
 */
class SdrController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()->current_workspace_id ?? 0);
    }

    public function index(): View
    {
        $wsId = $this->wsId();

        $campaigns = SdrCampaign::forWorkspace($wsId)->orderByDesc('id')->get()->map(function ($c) {
            $stats = DB::table('sdr_enrollments')
                ->where('sdr_campaign_id', $c->id)
                ->selectRaw("
                    COUNT(*) total,
                    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) active,
                    SUM(CASE WHEN status='routed' THEN 1 ELSE 0 END) routed,
                    SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) converted,
                    SUM(CASE WHEN status='stopped' THEN 1 ELSE 0 END) stopped
                ")->first();
            return [
                'id' => $c->id, 'name' => $c->name, 'is_active' => (bool) $c->is_active,
                'flow_id' => $c->flow_id, 'enroll_min_score' => $c->enroll_min_score,
                'route_score' => $c->route_score, 'route_team_id' => $c->route_team_id,
                'stop_on_reply' => (bool) $c->stop_on_reply, 'stop_on_convert' => (bool) $c->stop_on_convert,
                'stats' => [
                    'total' => (int) ($stats->total ?? 0), 'active' => (int) ($stats->active ?? 0),
                    'routed' => (int) ($stats->routed ?? 0), 'converted' => (int) ($stats->converted ?? 0),
                    'stopped' => (int) ($stats->stopped ?? 0),
                ],
            ];
        })->values();

        $rules = LeadScoringRule::forWorkspace($wsId)->orderBy('signal')->orderBy('sort')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'signal' => $r->signal,
                'points' => $r->points, 'conditions' => $r->conditions ?: [],
                'is_active' => (bool) $r->is_active, 'fired_count' => $r->fired_count,
            ])->values();

        // Cadence flows + sales teams for the pickers (best-effort; degrade to []).
        $flows = collect();
        if (class_exists(\App\Models\Flow::class)) {
            // The flows table column is `flow_name` (encrypted at rest) — there is
            // no `name` column, and it can't be ORDER BY'd in SQL (that sorts
            // ciphertext). Select flow_name, map to name, sort in PHP after decrypt.
            $flows = \App\Models\Flow::where('workspace_id', $wsId)
                ->get(['id', 'flow_name'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => (string) $f->flow_name])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        }
        $teams = collect();
        if (class_exists(\App\Models\Team::class) && \Illuminate\Support\Facades\Schema::hasTable('teams')) {
            $teams = \App\Models\Team::where('workspace_id', $wsId)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values();
        }

        return view('user.sdr.index', [
            'sdrSeed' => [
                'campaigns' => $campaigns,
                'rules'     => $rules,
                'flows'     => $flows,
                'teams'     => $teams,
                'signals'   => LeadScoringService::SIGNALS,
                'urls'      => [
                    'campaign' => url('/sdr/campaigns'),
                    'rule'     => url('/sdr/rules'),
                ],
            ],
        ]);
    }

    // ── Campaigns ────────────────────────────────────────────────────────
    public function storeCampaign(Request $request): JsonResponse
    {
        $data = $this->validateCampaign($request);
        $data['workspace_id'] = $this->wsId();
        $c = SdrCampaign::create($data);
        return response()->json(['ok' => true, 'id' => $c->id]);
    }

    public function updateCampaign(Request $request, int $id): JsonResponse
    {
        $c = SdrCampaign::forWorkspace($this->wsId())->findOrFail($id);
        $c->update($this->validateCampaign($request));
        return response()->json(['ok' => true]);
    }

    public function destroyCampaign(int $id): JsonResponse
    {
        SdrCampaign::forWorkspace($this->wsId())->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    private function validateCampaign(Request $request): array
    {
        $v = $request->validate([
            'name'             => 'required|string|max:120',
            'is_active'        => 'boolean',
            'flow_id'          => 'nullable|integer',
            'enroll_min_score' => 'nullable|integer|min:0|max:100',
            'route_score'      => 'nullable|integer|min:0|max:100',
            'route_team_id'    => 'nullable|integer',
            'stop_on_reply'    => 'boolean',
            'stop_on_convert'  => 'boolean',
        ]);
        return [
            'name'             => $v['name'],
            'is_active'        => (bool) ($v['is_active'] ?? true),
            'flow_id'          => $v['flow_id'] ?? null,
            'enroll_min_score' => (int) ($v['enroll_min_score'] ?? 0),
            'route_score'      => $v['route_score'] !== null ? (int) $v['route_score'] : null,
            'route_team_id'    => $v['route_team_id'] ?? null,
            'stop_on_reply'    => (bool) ($v['stop_on_reply'] ?? true),
            'stop_on_convert'  => (bool) ($v['stop_on_convert'] ?? true),
        ];
    }

    // ── Scoring rules ────────────────────────────────────────────────────
    public function storeRule(Request $request): JsonResponse
    {
        $data = $this->validateRule($request);
        $data['workspace_id'] = $this->wsId();
        $r = LeadScoringRule::create($data);
        return response()->json(['ok' => true, 'id' => $r->id]);
    }

    public function updateRule(Request $request, int $id): JsonResponse
    {
        $r = LeadScoringRule::forWorkspace($this->wsId())->findOrFail($id);
        $r->update($this->validateRule($request));
        return response()->json(['ok' => true]);
    }

    public function destroyRule(int $id): JsonResponse
    {
        LeadScoringRule::forWorkspace($this->wsId())->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    private function validateRule(Request $request): array
    {
        $v = $request->validate([
            'name'       => 'required|string|max:120',
            'signal'     => 'required|string|in:' . implode(',', LeadScoringService::SIGNALS),
            'points'     => 'required|integer|min:-100|max:100',
            'is_active'  => 'boolean',
            'conditions' => 'nullable|array',
        ]);
        return [
            'name'       => $v['name'],
            'signal'     => $v['signal'],
            'points'     => (int) $v['points'],
            'is_active'  => (bool) ($v['is_active'] ?? true),
            'conditions' => $v['conditions'] ?? null,
        ];
    }
}
