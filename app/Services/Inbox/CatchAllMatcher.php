<?php

namespace App\Services\Inbox;

use App\Models\Flow;
use App\Models\KeywordReply;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Default-route ("any inbound") selection shared by Baileys lookup and
 * WABA / IG / FB / SMS dispatch.
 *
 * Two published catch-alls used to resolve with orderBy('id') ASC, so a
 * Trigger-only empty graph (older id) silently beat a real shop flow.
 * Keywords always win first; among catch-alls we skip dead graphs and
 * pick the newest published, device-bound rule before a workspace-wide one.
 */
class CatchAllMatcher
{
    public const CATCH_ALL_TOKENS = ['any', '*', '.*', '.+'];

    public static function isCatchAllToken(string $token): bool
    {
        return in_array(mb_strtolower(trim($token)), self::CATCH_ALL_TOKENS, true);
    }

    /**
     * @return array{0: list<string>, 1: bool} real keyword tokens, and whether a catch-all token is present
     */
    public static function splitTriggerTokens(string $raw): array
    {
        $real = [];
        $catchAll = false;
        $raw = trim($raw);
        if ($raw === '') {
            return [$real, false];
        }
        foreach (preg_split('/\s*,\s*/', mb_strtolower($raw)) ?: [] as $kw) {
            $kw = trim((string) $kw);
            if ($kw === '') {
                continue;
            }
            if (self::isCatchAllToken($kw)) {
                $catchAll = true;
                continue;
            }
            $real[] = $kw;
        }

        return [$real, $catchAll];
    }

    public static function graphIsRunnable(array $data): bool
    {
        $nodes = $data['flowNodes'] ?? $data['nodes'] ?? [];
        $edges = $data['flowEdges'] ?? $data['edges'] ?? [];
        if (! is_array($nodes) || ! is_array($edges)) {
            return false;
        }
        if (count($nodes) < 2 || count($edges) < 1) {
            return false;
        }

        $start = null;
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $type = strtolower((string) ($n['type'] ?? ''));
            if (! empty($n['isStart']) || $type === 'trigger' || $type === 'start') {
                $start = $n;
                break;
            }
        }
        if ($start === null) {
            $start = is_array($nodes[0] ?? null) ? $nodes[0] : null;
        }
        $startId = $start['id'] ?? null;
        if ($startId === null || $startId === '') {
            return true;
        }
        foreach ($edges as $e) {
            if (! is_array($e)) {
                continue;
            }
            if ((string) ($e['source'] ?? '') === (string) $startId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id:int,device_id:?int,published_ts:int,runnable:bool}>  $rows
     * @return array{id:int,device_id:?int,published_ts:int,runnable:bool}|null
     */
    public static function chooseCatchAll(array $rows): ?array
    {
        $runnable = array_values(array_filter($rows, fn ($r) => ! empty($r['runnable'])));
        if ($runnable === []) {
            return null;
        }
        usort($runnable, function ($a, $b) {
            $aDev = ! empty($a['device_id']) ? 0 : 1;
            $bDev = ! empty($b['device_id']) ? 0 : 1;
            if ($aDev !== $bDev) {
                return $aDev <=> $bDev;
            }
            $ap = (int) ($a['published_ts'] ?? 0);
            $bp = (int) ($b['published_ts'] ?? 0);
            if ($ap !== $bp) {
                return $bp <=> $ap;
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        return $runnable[0];
    }

    public function pickKeywordReply(iterable $rules, ?int $preferDeviceId = null): ?KeywordReply
    {
        $indexed = [];
        $rows = [];
        foreach ($rules as $rule) {
            if (! $rule instanceof KeywordReply || ! $rule->status) {
                continue;
            }
            $runnable = $this->keywordReplyIsRunnable($rule);
            if (! $runnable) {
                Log::info('[CATCH-ALL] skip empty or unpublished flow', [
                    'rule_id' => $rule->id,
                    'flow_id' => $rule->flow_id,
                ]);
            }
            $deviceId = $rule->device_id ? (int) $rule->device_id : null;
            if ($preferDeviceId && $deviceId && $deviceId !== $preferDeviceId) {
                continue;
            }
            $flow = $this->flowFromRule($rule);
            $publishedTs = 0;
            if ($flow && $flow->published_at) {
                $publishedTs = $flow->published_at->getTimestamp();
            } elseif ($rule->updated_at) {
                $publishedTs = $rule->updated_at->getTimestamp();
            }
            $indexed[$rule->id] = $rule;
            $rows[] = [
                'id' => (int) $rule->id,
                'device_id' => $deviceId,
                'published_ts' => $publishedTs,
                'runnable' => $runnable,
            ];
        }
        $chosen = self::chooseCatchAll($rows);
        if ($chosen === null) {
            return null;
        }
        $pick = $indexed[$chosen['id']] ?? null;
        if ($pick) {
            Log::info('[CATCH-ALL] picked', [
                'rule_id' => $pick->id,
                'flow_id' => $pick->flow_id,
                'device_id' => $pick->device_id,
            ]);
        }

        return $pick;
    }

    /**
     * Native IG/FB flow tables: real keyword tokens first, then runnable catch-alls.
     *
     * @param  iterable<Flow>  $flows
     */
    public function pickFlowForInbound(iterable $flows, string $body): ?Flow
    {
        $text = mb_strtolower(trim($body));
        $keywordHits = [];
        $catchAlls = [];
        foreach ($flows as $flow) {
            if (! $flow instanceof Flow) {
                continue;
            }
            [$real, $isCatch] = self::splitTriggerTokens((string) ($flow->trigger_keywords ?? ''));
            $hit = false;
            foreach ($real as $kw) {
                if ($kw !== '' && mb_strpos($text, $kw) !== false) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $keywordHits[] = $flow;
            } elseif ($isCatch) {
                $catchAlls[] = $flow;
            }
        }
        if ($keywordHits !== []) {
            return $keywordHits[0];
        }

        $indexed = [];
        $rows = [];
        foreach ($catchAlls as $flow) {
            if (! $flow->is_published || ! $flow->is_active) {
                continue;
            }
            $runnable = self::graphIsRunnable($flow->decoded_flow_data);
            if (! $runnable) {
                Log::info('[CATCH-ALL] skip empty native flow graph', [
                    'flow_id' => $flow->id,
                    'flow_type' => $flow->flow_type,
                ]);
            }
            $publishedTs = $flow->published_at ? $flow->published_at->getTimestamp() : ($flow->updated_at?->getTimestamp() ?? 0);
            $indexed[$flow->id] = $flow;
            $rows[] = [
                'id' => (int) $flow->id,
                'device_id' => $flow->trigger_device_id ? (int) $flow->trigger_device_id : null,
                'published_ts' => $publishedTs,
                'runnable' => $runnable,
            ];
        }
        $chosen = self::chooseCatchAll($rows);

        return $chosen ? ($indexed[$chosen['id']] ?? null) : null;
    }

    private function keywordReplyIsRunnable(KeywordReply $rule): bool
    {
        $type = strtolower((string) ($rule->reply_type ?? 'custom')) ?: 'custom';
        if ($type !== 'flow') {
            return true;
        }
        $flow = $this->flowFromRule($rule);
        if (! $flow || ! $flow->is_published || ! $flow->is_active) {
            return false;
        }

        return self::graphIsRunnable($flow->decoded_flow_data);
    }

    private function flowFromRule(KeywordReply $rule): ?Flow
    {
        if (! $rule->flow_id) {
            return null;
        }
        if ($rule->relationLoaded('flow') && ($rule->flow->id ?? 0)) {
            return $rule->flow;
        }

        return Flow::find($rule->flow_id);
    }
}
