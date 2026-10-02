<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\ThreadsAccount;
use App\Models\ThreadsReplyLog;
use App\Models\ThreadsReplyRule;
use App\Models\Workspace;
use App\Services\Threads\ThreadsReplySweeper;
use Illuminate\Http\Request;

/**
 * Threads reply auto-responder rules — keyword → public reply and/or hide.
 * No webhook for replies, so the poller fires INLINE on this page load.
 */
class ThreadsReplyRuleController extends Controller
{
    public function index(Request $request)
    {
        $ws = $this->workspace($request);

        try { (new ThreadsReplySweeper())->run(); } catch (\Throwable $e) {}

        $accounts = ThreadsAccount::forWorkspace($ws->id)->connected()->orderByDesc('id')->get();
        $rules    = ThreadsReplyRule::forWorkspace($ws->id)->orderByDesc('id')->get();
        $activity = ThreadsReplyLog::where('workspace_id', $ws->id)->where('action', '!=', 'none')
            ->orderByDesc('id')->limit(30)->get();

        return view('user.threads.replies', compact('accounts', 'rules', 'activity'));
    }

    public function store(Request $request)
    {
        $ws = $this->workspace($request);
        $data = $request->validate([
            'name'               => 'nullable|string|max:120',
            'threads_account_id' => 'nullable|integer',
            'keyword'            => 'nullable|string|max:500',
            'keyword_mode'       => 'required|in:contains,exact,any',
            'reply_text'         => 'nullable|string|max:500',
            'hide'               => 'sometimes|boolean',
            'use_ai'             => 'sometimes|boolean',
            'ai_prompt'          => 'nullable|string|max:500',
        ]);

        if ($data['keyword_mode'] !== 'any' && trim((string) ($data['keyword'] ?? '')) === '') {
            return back()->with('error', __('Add a keyword, or choose "any reply".'));
        }
        if (trim((string) ($data['reply_text'] ?? '')) === '' && ! $request->boolean('use_ai') && ! $request->boolean('hide')) {
            return back()->with('error', __('Set a reply message, enable AI, or enable hide.'));
        }
        if ($data['threads_account_id'] ?? null) {
            abort_unless(ThreadsAccount::forWorkspace($ws->id)->whereKey($data['threads_account_id'])->exists(), 422);
        }

        ThreadsReplyRule::create([
            'workspace_id'       => $ws->id,
            'threads_account_id' => $data['threads_account_id'] ?: null,
            'name'               => $data['name'] ?? null,
            'keyword'            => $data['keyword'] ?? null,
            'keyword_mode'       => $data['keyword_mode'],
            'reply_text'         => $data['reply_text'] ?? null,
            'hide'               => $request->boolean('hide'),
            'use_ai'             => $request->boolean('use_ai'),
            'ai_prompt'          => $data['ai_prompt'] ?? null,
            'is_active'          => true,
        ]);

        return back()->with('status', __('Reply rule added.'));
    }

    public function toggle(Request $request, int $rule)
    {
        $ws = $this->workspace($request);
        $r = ThreadsReplyRule::forWorkspace($ws->id)->findOrFail($rule);
        $r->update(['is_active' => ! $r->is_active]);

        return back()->with('status', __('Rule updated.'));
    }

    public function destroy(Request $request, int $rule)
    {
        $ws = $this->workspace($request);
        ThreadsReplyRule::forWorkspace($ws->id)->whereKey($rule)->delete();

        return back()->with('status', __('Rule removed.'));
    }

    private function workspace(Request $request): Workspace
    {
        return Workspace::findOrFail((int) $request->user()->current_workspace_id);
    }
}
