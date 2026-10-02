<?php

namespace App\Services\Threads;

use App\Models\ThreadsAccount;
use App\Models\ThreadsScheduledPost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Publishes due Threads posts. No scheduler daemon in this app (see
 * routes/console.php) — this fires INLINE, cache-gated, from the Threads posts
 * page load, exactly like Instagram's ScheduledPostSweeper. Each due post runs
 * the 2-step container → publish flow.
 */
class ThreadsScheduledPostSweeper
{
    public function run(): void
    {
        // One sweep per 60s across the whole app.
        if (! Cache::add('threads_post_sweep_lock', 1, 60)) {
            return;
        }

        $due = ThreadsScheduledPost::query()
            ->where('status', 'scheduled')
            ->where('pending', false)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get();

        foreach ($due as $post) {
            $this->publishOne($post);
        }
    }

    public function publishOne(ThreadsScheduledPost $post): void
    {
        // Claim it so a concurrent request can't double-publish.
        $claimed = ThreadsScheduledPost::where('id', $post->id)
            ->where('status', 'scheduled')->where('pending', false)
            ->update(['pending' => true, 'status' => 'processing']);
        if (! $claimed) {
            return;
        }
        $post->refresh();

        $account = ThreadsAccount::find($post->threads_account_id);
        if (! $account || ! $account->isLive()) {
            $post->update(['status' => 'failed', 'pending' => false, 'last_error' => 'Threads account not connected']);
            return;
        }

        try {
            $svc  = new ThreadsService($account);
            $text = (string) ($post->text ?? '');
            $x    = ['cross_to_ig' => (bool) $post->cross_to_ig];   // also share to Instagram
            $res  = match ($post->media_type) {
                'image'    => $svc->publishImage((string) $post->image_url, $text, $x),
                'video'    => $svc->publishVideo((string) $post->video_url, $text, $x),
                'carousel' => $svc->publishCarousel(is_array($post->carousel_urls) ? $post->carousel_urls : [], $text, $x),
                default    => $svc->publishText($text, array_filter(['link_attachment' => $post->link_attachment]) + $x),
            };

            if ($res['ok'] ?? false) {
                $post->update([
                    'status'       => 'published',
                    'pending'      => false,
                    'media_id'     => $res['media_id'] ?? null,
                    'creation_id'  => $res['creation_id'] ?? $post->creation_id,
                    'published_at' => now(),
                    'last_error'   => null,
                ]);
            } else {
                $post->update([
                    'status'      => 'failed',
                    'pending'     => false,
                    'creation_id' => $res['creation_id'] ?? $post->creation_id,
                    'last_error'  => mb_substr((string) ($res['error'] ?? 'publish failed'), 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[THREADS] publishOne threw: ' . $e->getMessage(), ['post' => $post->id]);
            $post->update(['status' => 'failed', 'pending' => false, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
