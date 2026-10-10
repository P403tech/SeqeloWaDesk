<?php

namespace App\Services;

use App\Models\WaTemplate;
use App\Models\WaTemplateSample;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Admin + tenant visibility for templates installed from the sample library.
 */
class TemplatePushLifecycle
{
    /**
     * @return array{
     *     workspaces_with_pushed: int,
     *     total_installs: int,
     *     draft_not_submitted: int,
     *     pending_meta: int,
     *     approved_meta: int,
     *     rejected_meta: int,
     *     baileys_ready: int
     * }
     */
    public function adminSummary(?int $sampleId = null): array
    {
        $q = WaTemplate::query()->whereNotNull('source_sample_id');
        if ($sampleId) {
            $q->where('source_sample_id', $sampleId);
        }

        $rows = (clone $q)->get(['id', 'workspace_id', 'channel', 'status', 'meta_status', 'meta_template_id']);

        return [
            'workspaces_with_pushed' => $rows->pluck('workspace_id')->unique()->filter()->count(),
            'total_installs'         => $rows->count(),
            'draft_not_submitted'    => $rows->filter(fn ($t) => $this->templateStage($t) === 'not_submitted')->count(),
            'pending_meta'           => $rows->filter(fn ($t) => $this->templateStage($t) === 'pending')->count(),
            'approved_meta'          => $rows->filter(fn ($t) => $this->templateStage($t) === 'approved')->count(),
            'rejected_meta'          => $rows->filter(fn ($t) => $this->templateStage($t) === 'rejected')->count(),
            'baileys_ready'          => $rows->filter(fn ($t) => $this->templateStage($t) === 'baileys_ready')->count(),
        ];
    }

    public function adminRows(?int $sampleId = null, ?string $stage = null, int $perPage = 40): LengthAwarePaginator
    {
        $q = WaTemplate::query()
            ->whereNotNull('source_sample_id')
            ->with(['workspace:id,name,slug', 'sourceSample:id,slug,title'])
            ->orderByDesc('updated_at');

        if ($sampleId) {
            $q->where('source_sample_id', $sampleId);
        }

        if ($stage && $stage !== 'all') {
            $all = $q->get();
            $filtered = $all->filter(fn ($t) => $this->templateStage($t) === $stage)->values();
            $page = max(1, (int) request()->query('page', 1));
            $slice = $filtered->slice(($page - 1) * $perPage, $perPage)->values()
                ->each(function (WaTemplate $t) {
                    $t->setAttribute('lifecycle_stage', $this->templateStage($t));
                });

            return new \Illuminate\Pagination\LengthAwarePaginator(
                $slice,
                $filtered->count(),
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        return $q->paginate($perPage)->through(function (WaTemplate $t) {
            $t->setAttribute('lifecycle_stage', $this->templateStage($t));

            return $t;
        });
    }

    public function templateStage(WaTemplate $t): string
    {
        return $this->stage($t);
    }

    /**
     * WABA workspace checklist for "Your templates".
     *
     * @return array<string, mixed>|null
     */
    public function workspaceChecklist(int $workspaceId): ?array
    {
        if (! WorkspaceEngine::isWaba($workspaceId)) {
            return null;
        }

        $pushed = WaTemplate::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('source_sample_id')
            ->get();

        if ($pushed->isEmpty()) {
            return null;
        }

        $notSubmitted = $pushed->filter(fn ($t) => $this->templateStage($t) === 'not_submitted')->count();
        $pending = $pushed->filter(fn ($t) => $this->templateStage($t) === 'pending')->count();
        $approved = $pushed->filter(fn ($t) => $this->templateStage($t) === 'approved')->count();
        $rejected = $pushed->filter(fn ($t) => $this->templateStage($t) === 'rejected')->count();

        $currentStep = 3;
        if ($notSubmitted > 0) {
            $currentStep = 2;
        } elseif ($pending > 0) {
            $currentStep = 3;
        } elseif ($rejected > 0) {
            $currentStep = 2;
        }

        return [
            'installed'      => $pushed->count(),
            'not_submitted'  => $notSubmitted,
            'pending'        => $pending,
            'approved'       => $approved,
            'rejected'       => $rejected,
            'current_step'   => $currentStep,
            'filter_pending' => route('user.templates.index', ['view' => 'yours', 'status' => 'pending']),
            'filter_all'     => route('user.templates.index', ['view' => 'yours']),
        ];
    }

    /** @return Collection<int, Workspace> */
    public function activeWorkspacesForPicker(): Collection
    {
        return Workspace::query()
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    public function stageLabel(string $stage): string
    {
        return match ($stage) {
            'not_submitted' => __('Not submitted to Meta'),
            'pending'       => __('Pending Meta review'),
            'approved'      => __('Approved on Meta'),
            'rejected'      => __('Rejected by Meta'),
            'baileys_ready' => __('Ready (Unofficial API)'),
            default         => $stage,
        };
    }

    private function stage(WaTemplate $t): string
    {
        $channel = strtolower((string) ($t->channel ?? 'waba'));
        if ($channel !== 'waba') {
            return 'baileys_ready';
        }

        $meta = strtoupper((string) ($t->meta_status ?? ''));
        if ($meta === 'APPROVED') {
            return 'approved';
        }
        if ($meta === 'REJECTED') {
            return 'rejected';
        }
        if (! $t->meta_template_id) {
            return 'not_submitted';
        }

        return 'pending';
    }
}
