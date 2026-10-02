<?php

namespace App\Services\Threads;

use App\Models\ThreadsAccount;
use App\Models\ThreadsReplyLog;
use App\Models\ThreadsReplyRule;
use App\Models\ThreadsScheduledPost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Polls replies on published Threads posts and applies the auto-responder rules
 * (public reply + hide). Threads has NO webhook for replies, so this runs
 * INLINE, cache-gated, from the Threads pages — same pattern as the post
 * sweeper. The dedupe log keeps it idempotent.
 */
class ThreadsReplySweeper
{
    public function run(): void
    {
        if (! Cache::add('threads_reply_sweep_lock', 1, 60)) {
            return;
        }

        // Only accounts that actually have active rules — no point polling otherwise.
        $accountIds = ThreadsReplyRule::where('is_active', true)
            ->get(['threads_account_id', 'workspace_id'])
            ->flatMap(function ($r) {
                if ($r->threads_account_id) return [$r->threads_account_id];
                // account-agnostic rule → all connected accounts in its workspace
                return ThreadsAccount::forWorkspace($r->workspace_id)->connected()->pluck('id')->all();
            })->unique()->values();

        foreach ($accountIds as $accId) {
            $account = ThreadsAccount::find($accId);
            if ($account && $account->isLive()) {
                $this->pollAccount($account);
            }
        }
    }

    public function pollAccount(ThreadsAccount $account): void
    {
        // Recent published posts for this account (last 7 days), capped.
        $posts = ThreadsScheduledPost::where('threads_account_id', $account->id)
            ->where('status', 'published')->whereNotNull('media_id')
            ->where('published_at', '>=', now()->subDays(7))
            ->orderByDesc('published_at')->limit(15)->get();
        if ($posts->isEmpty()) {
            return;
        }

        $svc   = new ThreadsService($account);
        $rules = ThreadsReplyRule::where('is_active', true)
            ->where('workspace_id', $account->workspace_id)
            ->where(fn ($q) => $q->whereNull('threads_account_id')->orWhere('threads_account_id', $account->id))
            ->orderByDesc('id')->get();
        if ($rules->isEmpty()) {
            return;
        }

        foreach ($posts as $post) {
            foreach ($svc->getReplies((string) $post->media_id) as $reply) {
                $this->handleReply($account, $svc, $post, $rules, (array) $reply);
            }
        }
    }

    private function handleReply(ThreadsAccount $account, ThreadsService $svc, ThreadsScheduledPost $post, $rules, array $reply): void
    {
        $replyId = (string) ($reply['id'] ?? '');
        if ($replyId === '') {
            return;
        }
        // Never act on our OWN replies.
        if (($reply['username'] ?? '') !== '' && $account->username && strcasecmp((string) $reply['username'], (string) $account->username) === 0) {
            return;
        }
        // Already processed?
        if (ThreadsReplyLog::where('threads_account_id', $account->id)->where('reply_id', $replyId)->exists()) {
            return;
        }

        $text = (string) ($reply['text'] ?? '');
        $rule = null;
        foreach ($rules as $r) {
            // post targeting: null = any post, else must equal this post's media id
            if ($r->post_media_id && (string) $r->post_media_id !== (string) $post->media_id) continue;
            if ($r->matches($text)) { $rule = $r; break; }
        }

        $action = 'none';
        $repliedMediaId = null;
        if ($rule) {
            // AI reply (generated) takes priority over the fixed text when enabled;
            // falls back to the fixed reply_text if generation returns nothing.
            $body = (string) $rule->reply_text;
            if ($rule->use_ai) {
                $ai = $this->aiReply($account, $rule, $text);
                if ($ai !== '') $body = $ai;
            }
            if (trim($body) !== '') {
                $res = $svc->replyTo($replyId, $body);
                if ($res['ok'] ?? false) { $repliedMediaId = $res['media_id'] ?? null; $action = 'replied'; }
            }
            if ($rule->hide) {
                if ($svc->hideReply($replyId, true)) {
                    $action = $action === 'replied' ? 'replied_hidden' : 'hidden';
                }
            }
            try { $rule->increment('matched_count'); } catch (\Throwable $e) {}
        }

        try {
            ThreadsReplyLog::create([
                'workspace_id'       => $account->workspace_id,
                'threads_account_id' => $account->id,
                'reply_id'           => $replyId,
                'post_media_id'      => (string) $post->media_id,
                'from_username'      => $reply['username'] ?? null,
                'text'               => mb_substr($text, 0, 1000),
                'matched_rule_id'    => $rule->id ?? null,
                'action'             => $action,
                'replied_media_id'   => $repliedMediaId,
            ]);
        } catch (\Throwable $e) {
            // A unique-collision (raced poll) is fine — already logged.
        }
    }

    /**
     * Generate an on-brand public reply with the workspace's AI (BYOK → admin key
     * via AiKeyResolver). Picks the first provider that has a key. Returns '' when
     * no AI is available or generation fails, so the caller falls back to text.
     */
    private function aiReply(ThreadsAccount $account, ThreadsReplyRule $rule, string $customerText): string
    {
        $ws = \App\Models\Workspace::find($account->workspace_id);
        if (! $ws) return '';

        $provider = null;
        foreach (['openai', 'anthropic', 'gemini', 'deepseek', 'groq', 'mistral', 'openrouter', 'xai'] as $p) {
            if (\App\Services\AiKeyResolver::keyFor($ws, $p)) { $provider = $p; break; }
        }
        if (! $provider) return '';

        $brand = function_exists('brand_name') ? (string) brand_name() : 'our brand';
        $sys = "You reply to comments on our brand's Threads posts as the social media manager for {$brand}. "
            . "Write ONE short, warm, on-brand public reply, at most 480 characters, no hashtags unless natural. "
            . trim((string) $rule->ai_prompt);
        $user = "Someone replied to our post: \"" . mb_substr($customerText, 0, 800) . "\"\nWrite the reply.";

        try {
            $model = \App\Services\AiAgentService::fallbackModel($provider);
            $out = app(\App\Services\AiAgentService::class)->callProvider($provider, $model, (int) $ws->id, $sys, $user, 200, 0.7);
            return mb_substr(trim((string) ($out ?? '')), 0, 500);
        } catch (\Throwable $e) {
            Log::warning('[THREADS] AI reply failed: ' . $e->getMessage());
            return '';
        }
    }
}
