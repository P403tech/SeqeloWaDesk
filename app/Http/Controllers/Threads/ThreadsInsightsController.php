<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\ThreadsAccount;
use App\Models\ThreadsScheduledPost;
use App\Models\Workspace;
use App\Services\Threads\ThreadsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Threads insights dashboard — account totals + per-post metrics from the
 * official Threads Insights API. Results are cached briefly since each call
 * costs an API request and the numbers don't change by the second.
 */
class ThreadsInsightsController extends Controller
{
    private const TTL = 900; // 15 min

    public function index(Request $request)
    {
        $ws = $this->workspace($request);
        $refresh = $request->boolean('refresh');

        $accounts = ThreadsAccount::forWorkspace($ws->id)->connected()->orderByDesc('id')->get();

        $data = [];
        foreach ($accounts as $account) {
            $svc = new ThreadsService($account);

            $acctKey = "threads_insights_acct_{$account->id}";
            if ($refresh) Cache::forget($acctKey);
            $totals = Cache::remember($acctKey, self::TTL, fn () => $svc->accountInsights());

            // Follower demographics (country / age / gender) — cached separately.
            $demographics = [];
            foreach (['country', 'age', 'gender'] as $bd) {
                $dKey = "threads_demo_{$account->id}_{$bd}";
                if ($refresh) Cache::forget($dKey);
                $demographics[$bd] = Cache::remember($dKey, self::TTL, fn () => $svc->followerDemographics($bd));
            }

            $posts = ThreadsScheduledPost::where('threads_account_id', $account->id)
                ->where('status', 'published')->whereNotNull('media_id')
                ->orderByDesc('published_at')->limit(15)->get();

            $postRows = [];
            foreach ($posts as $post) {
                $pKey = "threads_insights_media_{$post->media_id}";
                if ($refresh) Cache::forget($pKey);
                $postRows[] = [
                    'post'     => $post,
                    'insights' => Cache::remember($pKey, self::TTL, fn () => $svc->mediaInsights((string) $post->media_id)),
                ];
            }

            $data[] = ['account' => $account, 'totals' => $totals, 'posts' => $postRows, 'demographics' => $demographics];
        }

        return view('user.threads.insights', compact('data'));
    }

    private function workspace(Request $request): Workspace
    {
        return Workspace::findOrFail((int) $request->user()->current_workspace_id);
    }
}
