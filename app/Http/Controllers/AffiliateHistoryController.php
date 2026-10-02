<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /affiliate-history — every signup attributed to the current user's
 * referral code, every credit grant that resulted from those signups,
 * and the per-day breakdown chart on top.
 *
 * Reuses the same shape the activity-log + message-history pages
 * use (KPI strip → charts row → filter bar + table + side aside →
 * bottom row) so the user gets a consistent navigation experience
 * across analytical pages.
 */
class AffiliateHistoryController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $user = Auth::user();
        $userId = $user?->id;

        $range  = $this->resolveRange($request->string('range')->toString() ?: '90d');
        // Smart default bucket — daily for short ranges, weekly for
        // 90d (otherwise the x-axis crowds with 90 ticks), monthly
        // for "all time". User-chosen tabs still override.
        $defaultBucket = $range['days'] <= 14 ? 'daily' : ($range['days'] <= 90 ? 'weekly' : 'monthly');
        $bucket = $request->string('bucket')->toString() ?: $defaultBucket;
        $page   = max(1, $request->integer('page') ?: 1);
        $q      = trim((string) $request->string('q')->toString());
        $perPage = 20;

        // Base referrals query (always for the current user as referrer).
        $baseRefs = Referral::query()
            ->forReferrer($userId)
            ->where('created_at', '>=', $range['from'])
            ->where('created_at', '<',  $range['to'])
            ->with('referred');

        // Free-text search — referee name / email / code-used. Cheap
        // because each user has at most ~thousands of referees, not
        // millions; we hydrate then filter in PHP because `name` and
        // `email` aren't indexed for partial match.
        $hydrated = (clone $baseRefs)
            ->orderByDesc('created_at')
            ->get();

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $hydrated = $hydrated->filter(function (Referral $r) use ($needle) {
                $hay = mb_strtolower(
                    ($r->referred?->name ?? '')
                    . ' ' . ($r->referred?->email ?? '')
                    . ' ' . $r->code_used
                );
                return str_contains($hay, $needle);
            })->values();
        }

        $total = $hydrated->count();
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pageCount);
        $rows = $hydrated->slice(($page - 1) * $perPage, $perPage)->values();

        // KPI strip — over the unfiltered (range-only) population.
        $stats = $this->stats($userId, $range);

        // Charts.
        $volume   = $this->volumeSeries($userId, $range, $bucket);
        $topCodes = $this->topCodes($userId, $range);
        $payouts  = $this->recentPayouts($userId, 8);

        // KPI: how the user's referral link / code looks.
        $referralUrl = url('/register?ref=' . urlencode($user->referral_code ?? ''));
        $signupReward = max(0, (int) SystemSetting::get('referral_signup_credits', 100));
        $creditsPerMessage = max(1, (int) SystemSetting::get('credits_per_message', 1));
        // `referral_signup_credits` is MONEY in minor units, not a credit
        // count. Convert through the same helper the payout uses so the
        // affiliate is shown what actually lands in their wallet.
        $signupRewardCredits = app(\App\Services\WalletService::class)->creditsForMinor($signupReward);

        // Refer & Earn pipeline (lifetime): joined → converted (paid) → pending,
        // + the real money earned. Small per-user population, so a single hydrate.
        $pipeRows = Referral::forReferrer($userId)->get(['status', 'award_transaction_id', 'reward_minor']);
        $pipeline = [
            'joined'      => $pipeRows->count(),
            'converted'   => $pipeRows->filter(fn ($r) => $r->status === Referral::STATUS_PAID || $r->award_transaction_id)->count(),
            'pending'     => $pipeRows->filter(fn ($r) => ($r->status ?: 'pending') === Referral::STATUS_PENDING && ! $r->award_transaction_id)->count(),
            'earnedMinor' => (int) $pipeRows->sum('reward_minor'),
        ];
        $referrerRewardMinor = \App\Services\ReferralService::referrerRewardMinor();
        $refereeRewardMinor  = \App\Services\ReferralService::refereeRewardMinor();
        $referralEnabled     = \App\Services\ReferralService::enabled();
        $referralWindowDays  = (int) SystemSetting::get('referral_window_days', 30);
        $defaultCurrency     = (string) SystemSetting::get('default_currency', 'USD');
        $waShareUrl = 'https://wa.me/?text=' . rawurlencode(
            trim(__('Join me on this WhatsApp platform') . ($refereeRewardMinor > 0 ? ' — ' . __('we both get a bonus') : '') . ': ' . $referralUrl)
        );

        $payload = [
            'rows'          => $rows->map(fn ($r) => $this->presentRow($r))->all(),
            'stats'         => $stats,
            'volume'        => $volume,
            'topCodes'      => $topCodes,
            'payouts'       => $payouts,
            'page'          => $page,
            'pageCount'     => $pageCount,
            'total'         => $total,
            'shownFrom'     => $total ? (($page - 1) * $perPage) + 1 : 0,
            'shownTo'       => min($total, $page * $perPage),
            'filters'       => compact('range', 'bucket', 'q'),
            'referralCode'  => $user?->referral_code ?: '—',
            'referralUrl'   => $referralUrl,
            'signupReward'  => $signupReward,
            // What the referrer actually receives. The view printed
            // `signupReward` (money-minor) followed by the word "credits",
            // which overstated the payout by 100 / credits_per_currency_unit.
            'signupRewardCredits' => $signupRewardCredits,
            'creditsPerMessage' => $creditsPerMessage,
            // Refer & Earn — pipeline + config for the summary + how-to cards.
            'pipeline'             => $pipeline,
            'referrerRewardMinor'  => $referrerRewardMinor,
            'refereeRewardMinor'   => $refereeRewardMinor,
            'referralEnabled'      => $referralEnabled,
            'referralWindowDays'   => $referralWindowDays,
            'defaultCurrency'      => $defaultCurrency,
            'waShareUrl'           => $waShareUrl,
        ];

        if ($request->boolean('partial')) {
            $payload['rowsHtml'] = view('user.affiliate-history._rows', ['rows' => $payload['rows']])->render();
            return response()->json(['ok' => true, 'data' => $payload]);
        }

        return view('user.affiliate-history.index', $payload);
    }

    public function export(Request $request): StreamedResponse
    {
        $userId = Auth::id();
        $range  = $this->resolveRange($request->string('range')->toString() ?: '90d');

        $referrals = Referral::query()
            ->forReferrer($userId)
            ->where('created_at', '>=', $range['from'])
            ->where('created_at', '<',  $range['to'])
            ->with('referred')
            ->orderByDesc('created_at')
            ->get();

        $filename = 'affiliate-history-' . now()->format('Ymd-His') . '.csv';
        return response()->stream(function () use ($referrals) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'referee_name', 'referee_email', 'code_used', 'credits_awarded', 'wallet_tx_id']);
            foreach ($referrals as $r) {
                fputcsv($out, [
                    $r->id,
                    optional($r->created_at)->toIso8601String(),
                    $r->referred?->name,
                    $r->referred?->email,
                    $r->code_used,
                    $r->credits_awarded,
                    $r->award_transaction_id,
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────────

    private function resolveRange(string $key): array
    {
        $now = now();
        return match ($key) {
            '7d'  => ['from' => $now->copy()->subDays(7),   'to' => $now->copy()->addSecond(), 'key' => '7d',  'days' => 7,  'label' => 'Last 7 days'],
            '30d' => ['from' => $now->copy()->subDays(30),  'to' => $now->copy()->addSecond(), 'key' => '30d', 'days' => 30, 'label' => 'Last 30 days'],
            '90d' => ['from' => $now->copy()->subDays(90),  'to' => $now->copy()->addSecond(), 'key' => '90d', 'days' => 90, 'label' => 'Last 90 days'],
            'all' => ['from' => Carbon::create(2020, 1, 1), 'to' => $now->copy()->addSecond(), 'key' => 'all', 'days' => 1825, 'label' => 'All time'],
            default => ['from' => $now->copy()->subDays(90), 'to' => $now->copy()->addSecond(), 'key' => '90d', 'days' => 90, 'label' => 'Last 90 days'],
        };
    }

    private function stats(?int $userId, array $range): array
    {
        $base = Referral::query()->forReferrer($userId);

        $signupsLifetime = (clone $base)->count();
        $creditsLifetime = (int) (clone $base)->sum('credits_awarded');

        $rangeQuery = (clone $base)
            ->where('created_at', '>=', $range['from'])
            ->where('created_at', '<',  $range['to']);
        $signupsRange = (clone $rangeQuery)->count();
        $creditsRange = (int) (clone $rangeQuery)->sum('credits_awarded');

        // 30d window for the "this month" stat regardless of selected range.
        $month = (clone $base)->where('created_at', '>=', now()->subDays(30));
        $signups30d = (clone $month)->count();
        $credits30d = (int) (clone $month)->sum('credits_awarded');

        // Average credits per signup across lifetime.
        $avgPerSignup = $signupsLifetime > 0 ? (int) round($creditsLifetime / $signupsLifetime) : 0;

        // Delta vs previous matching window.
        $prev = (clone $base)
            ->where('created_at', '>=', $range['from']->copy()->subDays($range['days']))
            ->where('created_at', '<',  $range['from']);
        $prevSignups = (clone $prev)->count();
        $deltaPct = $prevSignups > 0 ? round((($signupsRange - $prevSignups) / $prevSignups) * 100) : ($signupsRange > 0 ? 100 : 0);

        return [
            'signupsLifetime' => $signupsLifetime,
            'creditsLifetime' => $creditsLifetime,
            'signupsRange'    => $signupsRange,
            'creditsRange'    => $creditsRange,
            'signups30d'      => $signups30d,
            'credits30d'      => $credits30d,
            'avgPerSignup'    => $avgPerSignup,
            'deltaPct'        => $deltaPct,
        ];
    }

    private function volumeSeries(?int $userId, array $range, string $bucket): array
    {
        $bucket = in_array($bucket, ['daily', 'weekly', 'monthly'], true) ? $bucket : 'daily';

        $rows = Referral::query()
            ->forReferrer($userId)
            ->where('created_at', '>=', $range['from'])
            ->where('created_at', '<',  $range['to'])
            ->get(['credits_awarded', 'created_at']);

        if ($bucket === 'monthly') {
            $points = max(1, (int) ceil($range['days'] / 30));
            $keyOf = fn (Carbon $t) => $t->format('Y-m');
            $keys  = collect(range($points - 1, 0))->map(fn ($i) => now()->copy()->subMonths($i)->format('Y-m'));
            $labels= $keys->map(fn ($k) => Carbon::createFromFormat('Y-m', $k)->format('M'));
        } elseif ($bucket === 'weekly') {
            $points = max(1, (int) ceil($range['days'] / 7));
            $keyOf = fn (Carbon $t) => $t->copy()->startOfWeek()->format('Y-m-d');
            $keys  = collect(range($points - 1, 0))->map(fn ($i) => now()->copy()->subWeeks($i)->startOfWeek()->format('Y-m-d'));
            $labels= $keys->map(fn ($k) => Carbon::parse($k)->format('M d'));
        } else {
            $points = $range['days'];
            $keyOf = fn (Carbon $t) => $t->toDateString();
            $keys  = collect(range($points - 1, 0))->map(fn ($i) => now()->copy()->subDays($i)->toDateString());
            $labels= $keys->map(function ($k) use ($range) {
                $d = Carbon::parse($k);
                return $range['days'] <= 7 ? $d->format('D') : $d->format('M d');
            });
        }

        $byBucket = $rows->groupBy(fn (Referral $r) => $keyOf($r->created_at));

        $signups = $keys->map(fn ($k) => ($byBucket->get($k) ?? collect())->count())->all();
        $credits = $keys->map(fn ($k) => (int) (($byBucket->get($k) ?? collect())->sum('credits_awarded')))->all();

        return [
            'labels'  => $labels->all(),
            'signups' => $signups,
            'credits' => $credits,
            'bucket'  => $bucket,
        ];
    }

    private function topCodes(?int $userId, array $range): array
    {
        $rows = Referral::query()
            ->forReferrer($userId)
            ->where('created_at', '>=', $range['from'])
            ->where('created_at', '<',  $range['to'])
            ->select('code_used', DB::raw('COUNT(*) as signups'), DB::raw('SUM(credits_awarded) as credits'))
            ->groupBy('code_used')
            ->orderByDesc('signups')
            ->limit(5)
            ->get();
        $max = (int) ($rows->max('signups') ?: 1);
        return $rows->map(fn ($r) => [
            'code'    => $r->code_used,
            'signups' => (int) $r->signups,
            'credits' => (int) $r->credits,
            'pct'     => max(2, (int) round(($r->signups / $max) * 100)),
        ])->all();
    }

    private function recentPayouts(?int $userId, int $limit = 8): array
    {
        return WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('kind', WalletTransaction::KIND_CREDIT)
            // BUGFIX: rewards are written with source 'referral.paid' (the reward
            // fires on the referee's first paid top-up, not signup). The old
            // 'referral.signup' filter never matched, so this widget was always
            // empty. Match both so historical rows still show.
            ->whereIn('source', ['referral.paid', 'referral.signup'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (WalletTransaction $t) {
                return [
                    'id'           => $t->id,
                    'amount'       => (int) $t->amount,
                    'balanceAfter' => (int) $t->balance_after,
                    'description'  => $t->description ?: 'Referral bonus',
                    'when'         => optional($t->created_at)->format('M d, H:i'),
                    'human'        => optional($t->created_at)->diffForHumans(),
                ];
            })
            ->all();
    }

    private function presentRow(Referral $r): array
    {
        $name  = $r->referred?->name ?: ('User #' . $r->referred_user_id);
        $email = $r->referred?->email ?: '—';

        return [
            'id'             => $r->id,
            'when'           => optional($r->created_at)->format('H:i'),
            'date'           => optional($r->created_at)->format('M d, Y'),
            'human'          => optional($r->created_at)->diffForHumans(),
            'iso'            => optional($r->created_at)->toIso8601String(),
            'refereeName'    => $name,
            'refereeEmail'   => $email,
            'refereeInitials'=> $this->initials($name),
            'gradient'       => $this->avatarGradient($r->referred_user_id ?? 0),
            'codeUsed'       => $r->code_used,
            'creditsAwarded' => (int) $r->credits_awarded,
            'rewardMinor'    => (int) ($r->reward_minor ?? 0),
            'walletTxId'     => $r->award_transaction_id,
            // Real pipeline status (pending / paid / expired), not a guess from credits.
            'status'         => $r->status ?: ($r->award_transaction_id ? 'paid' : 'pending'),
        ];
    }

    private function avatarGradient(int $seed): string
    {
        $palette = [
            'from-wa-teal to-wa-deep',
            'from-accent-amber to-accent-coral',
            'from-wa-deep to-ink-900',
            'from-[#5B3D8A] to-[#13478A]',
            'from-[#7B5A14] to-accent-amber',
        ];
        return $palette[$seed % count($palette)];
    }

    private function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '··';
        $parts = preg_split('/\s+/', $name);
        $first = mb_substr($parts[0], 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last) ?: '··';
    }
}
