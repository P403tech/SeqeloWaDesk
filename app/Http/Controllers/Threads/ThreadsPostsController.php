<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\ThreadsAccount;
use App\Models\ThreadsScheduledPost;
use App\Models\Workspace;
use App\Services\Threads\ThreadsScheduledPostSweeper;
use App\Services\Threads\ThreadsTokenRefreshSweeper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Threads composer + scheduler. No scheduler daemon in this app, so due posts
 * are swept INLINE on each page load (like Instagram). Composing writes a
 * threads_scheduled_posts row; a past/blank schedule publishes immediately.
 */
class ThreadsPostsController extends Controller
{
    public function index(Request $request)
    {
        $ws = $this->workspace($request);

        // Inline sweepers — publish anything due + refresh tokens near expiry +
        // process any new replies against the auto-responder rules.
        try { (new ThreadsScheduledPostSweeper())->run(); } catch (\Throwable $e) {}
        try { (new ThreadsTokenRefreshSweeper())->run(); } catch (\Throwable $e) {}
        try { (new \App\Services\Threads\ThreadsReplySweeper())->run(); } catch (\Throwable $e) {}

        $accounts = ThreadsAccount::forWorkspace($ws->id)->orderByDesc('id')->get();
        $posts    = ThreadsScheduledPost::forWorkspace($ws->id)->orderByDesc('id')->limit(100)->get();

        return view('user.threads.posts', compact('accounts', 'posts'));
    }

    public function store(Request $request)
    {
        $ws = $this->workspace($request);

        $data = $request->validate([
            'threads_account_id' => 'required|integer',
            'media_type'         => 'required|in:text,image,video,carousel',
            'text'               => 'nullable|string|max:500',
            'link_attachment'    => 'nullable|url|max:1024',
            'scheduled_at'       => 'nullable|date',
            'cross_to_ig'        => 'sometimes|boolean',
            'media'              => 'nullable|array|max:20',
            'media.*'            => 'file|mimes:jpg,jpeg,png,mp4,mov|max:307200', // 300MB
        ]);

        $account = ThreadsAccount::forWorkspace($ws->id)->connected()->findOrFail($data['threads_account_id']);

        // Monthly scheduled-post limit (mirrors Instagram's effectiveLimit guard).
        $limit = (int) $ws->effectiveLimit('threads_scheduled_posts', 30);
        if ($limit > 0) {
            $used = ThreadsScheduledPost::forWorkspace($ws->id)
                ->where('created_at', '>=', now()->startOfMonth())->count();
            if ($used >= $limit) {
                return back()->with('error', __('You have reached your scheduled Threads posts limit for this month.'));
            }
        }

        // Uploaded media → public HTTPS URLs (Threads fetches them server-side).
        $urls = [];
        foreach ((array) $request->file('media', []) as $file) {
            $path   = $file->store('threads', 'public');
            $urls[] = [
                'type' => str_starts_with((string) $file->getMimeType(), 'video') ? 'video' : 'image',
                'url'  => url(Storage::disk('public')->url($path)),
            ];
        }

        $type = $data['media_type'];
        if ($type !== 'text' && empty($urls)) {
            return back()->with('error', __('Please attach media for this post type.'));
        }
        if (($data['text'] ?? '') === '' && $type === 'text') {
            return back()->with('error', __('Write something to post.'));
        }

        $post = ThreadsScheduledPost::create([
            'workspace_id'       => $ws->id,
            'threads_account_id' => $account->id,
            'media_type'         => $type,
            'text'               => $data['text'] ?? null,
            'link_attachment'    => $type === 'text' ? ($data['link_attachment'] ?? null) : null,
            'image_url'          => $type === 'image' ? ($urls[0]['url'] ?? null) : null,
            'video_url'          => $type === 'video' ? ($urls[0]['url'] ?? null) : null,
            'carousel_urls'      => $type === 'carousel' ? $urls : null,
            'scheduled_at'       => $data['scheduled_at'] ?? null,
            'cross_to_ig'        => $request->boolean('cross_to_ig'),
            'status'             => 'scheduled',
        ]);

        // Publish now when there's no future schedule.
        if (empty($data['scheduled_at']) || strtotime($data['scheduled_at']) <= time()) {
            (new ThreadsScheduledPostSweeper())->publishOne($post);
            $post->refresh();
            return back()->with($post->status === 'published' ? 'status' : 'error',
                $post->status === 'published' ? __('Posted to Threads.') : __('Could not post: ') . $post->last_error);
        }

        return back()->with('status', __('Threads post scheduled.'));
    }

    public function destroy(Request $request, int $post)
    {
        $ws = $this->workspace($request);
        ThreadsScheduledPost::forWorkspace($ws->id)->whereKey($post)
            ->where('status', '!=', 'published')->delete();

        return back()->with('status', __('Post removed.'));
    }

    private function workspace(Request $request): Workspace
    {
        return Workspace::findOrFail((int) $request->user()->current_workspace_id);
    }
}
