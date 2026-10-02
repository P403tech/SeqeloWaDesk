<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\FacebookCommentRule;
use App\Models\FacebookPage;
use App\Models\Flow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD for Facebook comment auto-reply rules (facebook_comment_rules). The
 * engine that fires them lives in FacebookIngestService::fireFbCommentAutoReplies
 * — this only lets an operator create/edit/delete the rules.
 */
class FacebookCommentRuleController extends Controller
{
    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** Rules the current workspace owns (defensive scope on every mutation). */
    private function ruleFor(int $id): FacebookCommentRule
    {
        return FacebookCommentRule::query()
            ->where('workspace_id', $this->workspaceId())
            ->findOrFail($id);
    }

    public function index(): View
    {
        $wsId = $this->workspaceId();

        return view('user.facebook.comment-rules', [
            'pages' => FacebookPage::forWorkspace($wsId)->connected()->orderBy('name')->get(),
            'flows' => Flow::query()
                ->where('workspace_id', $wsId)
                ->where('flow_type', 'facebook')
                ->where('is_active', true)
                ->orderByDesc('id')
                ->get(['id', 'flow_name']),
            'rules' => FacebookCommentRule::query()
                ->where('workspace_id', $wsId)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // Page must belong to this workspace.
        FacebookPage::forWorkspace($this->workspaceId())->connected()->findOrFail($data['fb_page_id']);

        FacebookCommentRule::query()->create($data + [
            'workspace_id'  => $this->workspaceId(),
            'matched_count' => 0,
        ]);

        return back()->with('status', __('Comment rule created.'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $rule = $this->ruleFor($id);
        $data = $this->validated($request);
        FacebookPage::forWorkspace($this->workspaceId())->connected()->findOrFail($data['fb_page_id']);

        $rule->update($data);

        return back()->with('status', __('Comment rule updated.'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->ruleFor($id)->delete();

        return back()->with('status', __('Comment rule deleted.'));
    }

    /** Shared validation + normalisation for store/update. */
    private function validated(Request $request): array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'fb_page_id'   => 'required|integer',
            'name'         => 'nullable|string|max:191',
            'post_id'      => 'nullable|string|max:64',
            'keyword'      => 'nullable|string|max:191',
            'keyword_mode' => 'required|in:contains,exact,any',
            'public_reply' => 'nullable|string|max:8000',
            'dm_text'      => 'nullable|string|max:2000',
            'dm_flow_id'   => 'nullable|integer',
            'is_active'    => 'nullable|boolean',
        ], [], ['fb_page_id' => __('Facebook page')]);

        // A rule that does nothing is pointless — require at least one action, and
        // (unless it's a catch-all "any") a keyword to match on.
        $validator->after(function ($v) use ($request) {
            if (empty($request->public_reply) && empty($request->dm_text) && empty($request->dm_flow_id)) {
                $v->errors()->add('public_reply', __('Add at least a public reply, a DM, or a flow.'));
            }
            if ($request->input('keyword_mode') !== 'any' && trim((string) $request->input('keyword')) === '') {
                $v->errors()->add('keyword', __('Enter a keyword, or choose the "any comment" mode.'));
            }
        });

        $data = $validator->validate(); // throws → redirect back with errors on a web request

        // A flow chosen from another workspace is dropped rather than trusted.
        if (!empty($data['dm_flow_id'])) {
            $ok = Flow::query()->where('workspace_id', $this->workspaceId())
                ->where('flow_type', 'facebook')->whereKey($data['dm_flow_id'])->exists();
            if (!$ok) $data['dm_flow_id'] = null;
        }

        $data['is_active'] = (bool) ($request->boolean('is_active'));
        $data['post_id']   = trim((string) ($data['post_id'] ?? '')) ?: null;

        return $data;
    }
}
