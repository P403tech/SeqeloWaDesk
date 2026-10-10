<?php

namespace App\Support;

use App\Models\Flow;
use App\Services\WorkspaceEngine;

/**
 * Shared flow graph helpers for cloning admin templates into workspaces.
 */
class FlowGraphSupport
{
    public static function ensureNodePositions(array $flowData): array
    {
        $nodes = $flowData['flowNodes'] ?? null;
        if (! is_array($nodes)) {
            return $flowData;
        }
        foreach ($nodes as $i => &$n) {
            if (! is_array($n)) {
                continue;
            }
            if (! isset($n['x']) || ! is_numeric($n['x'])) {
                $n['x'] = 120 + ($i % 3) * 260;
            }
            if (! isset($n['y']) || ! is_numeric($n['y'])) {
                $n['y'] = 120 + intdiv($i, 3) * 180;
            }
        }
        unset($n);
        $flowData['flowNodes'] = $nodes;

        return $flowData;
    }

    /**
     * @return array<string, mixed>
     */
    public static function extractTriggerColumns(array $flowData): array
    {
        $trigger = null;
        foreach (($flowData['flowNodes'] ?? []) as $n) {
            if (($n['type'] ?? null) === 'trigger') {
                $trigger = $n;
                break;
            }
        }
        $d = is_array($trigger['data'] ?? null) ? $trigger['data'] : [];
        $kind = (string) ($d['kind'] ?? 'keyword');
        if (! in_array($kind, Flow::TRIGGER_KINDS, true)) {
            $kind = 'keyword';
        }
        $value = null;
        if ($kind === 'tag_added') {
            $value = (int) ($d['tagId'] ?? 0) ?: null;
        }
        if ($kind === 'group_join') {
            $value = (int) ($d['groupId'] ?? 0) ?: null;
        }
        if ($kind === 'campaign_engagement') {
            $value = (int) ($d['campaignId'] ?? 0) ?: null;
        }
        if ($kind === 'deal_stage_changed') {
            $value = (int) ($d['stageId'] ?? 0) ?: null;
        }
        if (in_array($kind, ['deal_created', 'deal_won', 'deal_lost'], true)) {
            $value = (int) ($d['pipelineId'] ?? 0);
        }
        if (in_array($kind, ['deal_assigned', 'conversation_assigned'], true)) {
            $value = (int) ($d['userId'] ?? 0);
        }
        if ($kind === 'task_due') {
            $value = 0;
        }
        if ($kind === 'no_activity') {
            $value = max(1, min(8760, (int) ($d['hours'] ?? 48)));
        }
        if (in_array($kind, ['contact_created', 'opt_in', 'order_placed', 'away', 'out_of_hours'], true)) {
            $value = 0;
        }
        $engines = [
            WorkspaceEngine::ENGINE_BAILEYS,
            WorkspaceEngine::ENGINE_WABA,
            WorkspaceEngine::ENGINE_TWILIO,
            'instagram',
            'facebook',
            'tiktok',
            'telegram',
            'line',
            'wechat',
            'viber',
            'email',
        ];
        $rawSender = trim((string) ($d['deviceId'] ?? ''));
        $deviceId = null;
        $provider = null;
        if ($rawSender !== '') {
            if (str_contains($rawSender, ':')) {
                [$eng, $rawId] = explode(':', $rawSender, 2);
                $eng = strtolower(trim($eng));
                if (in_array($eng, $engines, true)) {
                    $provider = $eng;
                    $deviceId = (int) $rawId ?: null;
                }
            } else {
                $deviceId = (int) $rawSender ?: null;
            }
        }
        $mode = strtolower(trim((string) ($d['keywordMode'] ?? $d['triggerMode'] ?? '')));
        $keywords = null;
        if ($kind === 'keyword') {
            $keywords = $mode === 'any'
                ? 'any'
                : (trim((string) ($d['keywords'] ?? '')) ?: null);
        }
        if ($kind === 'comment_to_dm') {
            $value = 0;
            if (strtolower((string) ($d['channel'] ?? '')) === 'facebook') {
                $keywords = trim((string) ($d['keywords'] ?? '')) ?: null;
            } else {
                $ids = array_values(array_filter(array_map(
                    fn ($x) => (int) $x,
                    is_array($d['keywordRuleIds'] ?? null) ? $d['keywordRuleIds'] : []
                )));
                $keywords = $ids ? implode(',', $ids) : null;
            }
        }

        return [
            'trigger_kind'      => $kind,
            'trigger_value'     => $value,
            'trigger_device_id' => $deviceId,
            'trigger_keywords'  => $keywords,
        ] + ($provider ? ['provider' => $provider] : []);
    }

    /** @var list<string> */
    public const FLOW_TYPES = [
        'chat', 'call', 'instagram', 'facebook', 'tiktok', 'telegram',
        'line', 'wechat', 'viber', 'email', 'webchat',
    ];

    public static function normalizeFlowType(?string $type): string
    {
        $type = (string) $type;

        return in_array($type, self::FLOW_TYPES, true) ? $type : 'chat';
    }
}
