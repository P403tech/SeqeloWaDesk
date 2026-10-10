<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\FlowTemplate;
use App\Models\Workspace;
use App\Support\FlowGraphSupport;

/**
 * Installs an admin flow template into every active workspace as an
 * unpublished draft flow (same as tenant clone, but admin-driven).
 * Published copies are left alone so live automations are not overwritten.
 */
class FlowTemplatePublisher
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    /**
     * @param  list<int>|null  $workspaceIds  When non-empty, only these active workspaces.
     */
    public function push(FlowTemplate $template, ?array $workspaceIds = null): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $workspaceQuery = Workspace::query()->where('status', true);
        if ($workspaceIds !== null && $workspaceIds !== []) {
            $workspaceQuery->whereIn('id', $workspaceIds);
        }

        $workspaceQuery
            ->orderBy('id')
            ->chunkById(100, function ($workspaces) use ($template, &$created, &$updated, &$skipped) {
                foreach ($workspaces as $ws) {
                    $result = $this->installIntoWorkspace($ws, $template);
                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                }
            });

        $template->forceFill(['last_pushed_at' => now()])->save();

        return compact('created', 'updated', 'skipped');
    }

    private function installIntoWorkspace(Workspace $ws, FlowTemplate $template): string
    {
        $existing = Flow::query()
            ->where('workspace_id', $ws->id)
            ->where('source_flow_template_id', $template->id)
            ->first();

        if ($existing && $existing->is_published) {
            return 'skipped';
        }

        $raw = is_array($template->flow_data) ? $template->flow_data : ['flowNodes' => [], 'flowEdges' => []];
        $flowData = FlowGraphSupport::ensureNodePositions($raw);
        $payload = [
            'user_id'                 => $ws->owner_user_id,
            'workspace_id'            => $ws->id,
            'source_flow_template_id' => $template->id,
            'flow_name'               => $template->name,
            'flow_data'               => json_encode($flowData),
            'flow_type'               => FlowGraphSupport::normalizeFlowType($template->flow_type),
            'category'                => $template->category,
            'is_published'            => false,
            'is_active'               => true,
        ] + FlowGraphSupport::extractTriggerColumns($flowData);

        if ($existing) {
            $existing->update($payload);
            $existing->saveFlowFile($flowData);

            return 'updated';
        }

        $flow = Flow::create($payload);
        $flow->saveFlowFile($flowData);

        return 'created';
    }
}
