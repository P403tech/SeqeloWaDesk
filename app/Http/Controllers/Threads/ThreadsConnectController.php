<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\ThreadsAccount;
use App\Models\Workspace;
use App\Services\Threads\ThreadsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Threads (Meta) connect flow — OAuth authorize → callback, plus a paste-token
 * fallback and disconnect. App credentials resolve workspace-first (BYO
 * ownThreadsApp) then admin SystemSetting (threads_app_id / threads_app_secret),
 * mirroring the Instagram connect resolution order.
 */
class ThreadsConnectController extends Controller
{
    /** Landing → the composer/posts page (connect lives in its empty state + here). */
    public function index(Request $request)
    {
        return redirect()->route('user.threads.posts');
    }

    /** Step 1 — redirect to the Threads authorization window. */
    public function start(Request $request)
    {
        $ws  = $this->workspace($request);
        $app = $this->resolveApp($ws);
        if (! $app) {
            return redirect()->route('user.threads.posts')
                ->with('error', __('Threads is not configured yet. Add your Threads app keys first.'));
        }

        $state = Str::random(40);
        $request->session()->put('threads_oauth_state', $state);

        return redirect()->away(ThreadsService::authorizeUrl($app['id'], $this->redirectUri(), $state));
    }

    /** Step 2 — OAuth callback: exchange code → long-lived token → store account. */
    public function callback(Request $request)
    {
        $ws  = $this->workspace($request);
        $app = $this->resolveApp($ws);
        $back = fn (string $type, string $msg) => redirect()->route('user.threads.posts')->with($type, $msg);

        if ($request->filled('error')) {
            return $back('error', __('Threads connection was cancelled.'));
        }
        if (! $app) {
            return $back('error', __('Threads is not configured yet.'));
        }
        if ($request->query('state') !== $request->session()->pull('threads_oauth_state')) {
            return $back('error', __('Connection expired — please try again.'));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $back('error', __('Threads returned no authorization code.'));
        }

        // Account-limit guard.
        $limit = (int) $ws->effectiveLimit('threads_accounts', 1);
        if ($limit > 0 && ThreadsAccount::forWorkspace($ws->id)->count() >= $limit) {
            return $back('error', __('You have reached your connected Threads accounts limit.'));
        }

        // code → short token → long-lived token → profile.
        $short = ThreadsService::exchangeCode($app['id'], $app['secret'], $this->redirectUri(), $code);
        if (! $short) {
            return $back('error', __('Could not complete the Threads connection. Please try again.'));
        }
        $long = ThreadsService::longLivedToken($app['secret'], $short['access_token']);
        $token = $long['access_token'] ?? $short['access_token'];
        $expiresIn = (int) ($long['expires_in'] ?? 3600);

        $profile = ThreadsService::fetchProfile($token);
        $threadsUserId = (string) ($profile['id'] ?? $short['user_id'] ?? '');
        if ($threadsUserId === '') {
            return $back('error', __('Could not read the Threads profile. Please try again.'));
        }

        ThreadsAccount::updateOrCreate(
            ['workspace_id' => $ws->id, 'threads_user_id' => $threadsUserId],
            [
                'user_id'          => $request->user()->id,
                'username'         => $profile['username'] ?? null,
                'name'             => $profile['name'] ?? null,
                'profile_pic_url'  => $profile['threads_profile_picture_url'] ?? null,
                'access_token'     => $token,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'scopes'           => explode(',', ThreadsService::SCOPES),
                'status'           => 'connected',
                'last_error'       => null,
            ]
        );

        return $back('status', __('Threads account connected.'));
    }

    /** Paste-token fallback (a long-lived token + the profile is fetched from it). */
    public function manual(Request $request)
    {
        $ws = $this->workspace($request);
        $data = $request->validate(['access_token' => 'required|string|max:1000']);

        $token   = trim($data['access_token']);
        $profile = ThreadsService::fetchProfile($token);
        if (! $profile || empty($profile['id'])) {
            return back()->with('error', __('That token could not be verified with Threads.'));
        }

        ThreadsAccount::updateOrCreate(
            ['workspace_id' => $ws->id, 'threads_user_id' => (string) $profile['id']],
            [
                'user_id'          => $request->user()->id,
                'username'         => $profile['username'] ?? null,
                'name'             => $profile['name'] ?? null,
                'profile_pic_url'  => $profile['threads_profile_picture_url'] ?? null,
                'access_token'     => $token,
                'token_expires_at' => now()->addDays(60),
                'scopes'           => explode(',', ThreadsService::SCOPES),
                'status'           => 'connected',
                'last_error'       => null,
            ]
        );

        return back()->with('status', __('Threads account connected.'));
    }

    public function disconnect(Request $request, int $account)
    {
        $ws = $this->workspace($request);
        ThreadsAccount::forWorkspace($ws->id)->whereKey($account)->delete();

        return back()->with('status', __('Threads account disconnected.'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function workspace(Request $request): Workspace
    {
        return Workspace::findOrFail((int) $request->user()->current_workspace_id);
    }

    private function redirectUri(): string
    {
        return url('/threads/callback');
    }

    /** Workspace BYO app first, else admin SystemSetting. Returns ['id','secret'] or null. */
    private function resolveApp(Workspace $ws): ?array
    {
        if ($own = $ws->ownThreadsApp()) {
            return $own;
        }
        $id     = trim((string) SystemSetting::get('threads_app_id', ''));
        $secret = trim((string) SystemSetting::get('threads_app_secret', ''));

        return ($id !== '' && $secret !== '') ? ['id' => $id, 'secret' => $secret] : null;
    }
}
