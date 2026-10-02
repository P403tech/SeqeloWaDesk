<?php

namespace App\Services\Campaign;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Best time to send" — pure statistics, NO AI.
 *
 * Aggregates when a workspace's audience actually ENGAGED with past campaigns
 * (reads / replies / button-clicks) into a 7×24 weekday×hour grid, bucketed in
 * the workspace's timezone (the same offset-shift-in-SQL trick the campaign
 * analytics heatmap uses), then ranks the peak cell. Replies and clicks weigh
 * more than a passive read because they signal real intent.
 *
 * Everything here is a GROUP BY over indexed-ish columns; no model is trained,
 * nothing is inferred — the "suggestion" is simply the highest-scoring hour that
 * clears a minimum-sample bar.
 */
class CampaignBestTimeService
{
    /** Weights: a reply/click is worth more than a passive read. */
    private const W_READ  = 1.0;
    private const W_REPLY = 2.0;
    private const W_CLICK = 1.5;

    /** A cell needs at least this many raw engagements to be "the best time" — so
     *  one lucky read on a quiet Sunday can't win. */
    private const MIN_SAMPLES = 20;

    /** Only look at the last N days so the picture reflects current behaviour. */
    private const LOOKBACK_DAYS = 120;

    /**
     * @return array{
     *   has_data: bool, tz: string, samples: int,
     *   grid: array<int, array<int, float>>,        // grid[dow 0=Mon..6=Sun][hour 0..23] = weighted score
     *   best: array{dow:int, hour:int, score:float}|null,
     * }
     */
    public function heatmap(int $workspaceId): array
    {
        $tz     = function_exists('wa_tz') ? (string) wa_tz($workspaceId) : config('app.timezone', 'UTC');
        try { $offset = Carbon::now($tz)->utcOffset(); } catch (\Throwable $e) { $tz = 'UTC'; $offset = 0; }

        // Weighted score grid + a raw-count grid for the sample threshold.
        $grid = array_fill(0, 7, array_fill(0, 24, 0.0));
        $raw  = array_fill(0, 7, array_fill(0, 24, 0));
        $total = 0;

        foreach ([['read_at', self::W_READ], ['responded_at', self::W_REPLY], ['clicked_at', self::W_CLICK]] as [$col, $weight]) {
            foreach ($this->bucketed($workspaceId, $col, $offset) as $r) {
                $dow  = (int) $r->dow;   // MySQL WEEKDAY(): 0=Mon..6=Sun
                $hr   = (int) $r->hr;
                $cnt  = (int) $r->c;
                if ($dow < 0 || $dow > 6 || $hr < 0 || $hr > 23) continue;
                $grid[$dow][$hr] += $cnt * $weight;
                $raw[$dow][$hr]  += $cnt;
                $total += $cnt;
            }
        }

        // Best cell: highest weighted score among cells that clear the sample bar.
        $best = null;
        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                if ($raw[$d][$h] < self::MIN_SAMPLES) continue;
                if ($best === null || $grid[$d][$h] > $best['score']) {
                    $best = ['dow' => $d, 'hour' => $h, 'score' => round($grid[$d][$h], 1)];
                }
            }
        }

        return [
            'has_data' => $total > 0,
            'tz'       => $tz,
            'samples'  => $total,
            'grid'     => $grid,
            'best'     => $best,
        ];
    }

    /** One weekday×hour GROUP BY for a single engagement column, TZ-shifted. */
    private function bucketed(int $workspaceId, string $col, int $offsetMinutes): \Illuminate\Support\Collection
    {
        // $col is a fixed whitelist value (never user input) — safe to inline.
        $since = now()->subDays(self::LOOKBACK_DAYS)->toDateTimeString();

        return DB::table('wp_campaign_contacts as cc')
            ->join('wpcampaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->where('c.workspace_id', $workspaceId)
            ->whereNotNull("cc.{$col}")
            ->where("cc.{$col}", '>=', $since)
            ->selectRaw("WEEKDAY(cc.{$col} + INTERVAL ? MINUTE) as dow, HOUR(cc.{$col} + INTERVAL ? MINUTE) as hr, COUNT(*) as c", [$offsetMinutes, $offsetMinutes])
            ->groupBy('dow', 'hr')
            ->get();
    }
}
