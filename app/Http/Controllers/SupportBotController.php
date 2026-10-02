<?php

namespace App\Http\Controllers;

use App\Models\SupportBotLog;
use App\Models\SupportBotSource;
use App\Services\SupportBot\SupportBotService;
use App\Support\SupportBotSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User-facing Client Support Bot endpoints. Any logged-in user can ask; the
 * server-side ladder (docs → AI → web → contact) decides how to answer. The
 * admin configures everything — the user only sends a question.
 */
class SupportBotController extends Controller
{
    public function ask(Request $request, SupportBotService $svc): JsonResponse
    {
        if (! SupportBotSettings::enabled()) {
            return response()->json(['ok' => false, 'error' => 'unavailable'], 404);
        }
        $data = $request->validate([
            'question'        => ['required', 'string', 'max:2000'],
            'session_id'      => ['nullable', 'string', 'max:64'],
            'history'         => ['nullable', 'array', 'max:20'],
            'history.*.role'  => ['nullable', 'string', 'max:16'],
            'history.*.text'  => ['nullable', 'string', 'max:2000'],
        ]);

        // Normalise the client-sent thread to the last few clean turns.
        $history = collect($data['history'] ?? [])
            ->map(fn ($h) => ['role' => (($h['role'] ?? '') === 'user') ? 'user' : 'bot', 'text' => trim((string) ($h['text'] ?? ''))])
            ->filter(fn ($h) => $h['text'] !== '')
            ->take(8)
            ->values()
            ->all();

        $result = $svc->ask(
            $data['question'],
            (int) ($request->user()?->id ?? 0) ?: null,
            $data['session_id'] ?? null,
            $request->ip(),
            $history,
        );

        return response()->json($result);
    }

    /**
     * The signed-in user's previous conversation, so the widget can reload the
     * existing chat after a logout / page refresh instead of starting blank.
     * Rebuilt from the stored Q/A logs (oldest first): each log becomes a user
     * turn followed by the bot's answer, carrying its log_id + rating so the
     * user can keep talking on the same thread and rate past answers.
     */
    public function history(Request $request): JsonResponse
    {
        if (! SupportBotSettings::enabled()) {
            return response()->json(['ok' => false, 'error' => 'unavailable'], 404);
        }
        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0) {
            return response()->json(['ok' => true, 'turns' => []]);
        }

        // Turns for ONE conversation. When the History list opens a past chat it
        // passes ?session=<id> → return that chat in full (hidden or not). With
        // no session it returns the current (un-hidden) turns, so an open/refresh
        // reloads the active chat. Newest-first from the DB, flipped to chrono.
        $session = trim((string) $request->query('session', ''));
        $logs = SupportBotLog::query()
            ->where('user_id', $userId)
            ->when($session !== '',
                fn ($q) => $q->where('session_id', $session),
                fn ($q) => $q->whereNull('hidden_at'),
            )
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'question', 'answer', 'matched', 'rating', 'engine'])
            ->reverse()
            ->values();

        $turns = [];
        foreach ($logs as $log) {
            if (trim((string) $log->question) !== '') {
                $turns[] = ['role' => 'user', 'text' => $log->question];
            }
            $turns[] = [
                'role'    => 'bot',
                'text'    => (string) $log->answer,
                'log_id'  => $log->id,
                'rating'  => $log->rating === null ? 0 : (int) $log->rating,
                'engine'  => $log->engine,
                'matched' => (bool) $log->matched,
            ];
        }

        return response()->json(['ok' => true, 'turns' => $turns]);
    }

    /**
     * List the user's previous chats for the History panel. Each chat is a
     * session_id group; we return its first question as the title, when it was
     * last active, and how many turns it holds. Newest activity first.
     */
    public function sessions(Request $request): JsonResponse
    {
        if (! SupportBotSettings::enabled()) {
            return response()->json(['ok' => false, 'error' => 'unavailable'], 404);
        }
        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0) {
            return response()->json(['ok' => true, 'sessions' => []]);
        }

        // Newest-first rows; group by session in that order so the list is
        // ordered by most-recent activity and each group's LAST-seen (oldest)
        // question becomes the title.
        $rows = SupportBotLog::query()
            ->where('user_id', $userId)
            ->whereNotNull('session_id')
            ->where('session_id', '!=', '')
            ->orderByDesc('id')
            ->limit(600)
            ->get(['question', 'session_id', 'created_at']);

        $groups = [];
        foreach ($rows as $r) {
            $sid = (string) $r->session_id;
            if (! isset($groups[$sid])) {
                $groups[$sid] = ['session_id' => $sid, 'title' => null, 'last_at' => $r->created_at, 'count' => 0];
            }
            $groups[$sid]['count']++;
            $q = trim((string) $r->question);
            if ($q !== '') {
                $groups[$sid]['title'] = $q; // iterating newest→oldest, so the last write is the oldest question
            }
        }

        $sessions = array_values(array_map(fn ($g) => [
            'session_id' => $g['session_id'],
            'title'      => \Illuminate\Support\Str::limit($g['title'] ?: __('Conversation'), 70),
            'when'       => optional($g['last_at'])->diffForHumans(),
            'count'      => $g['count'],
        ], $groups));

        return response()->json(['ok' => true, 'sessions' => $sessions]);
    }

    /** Rate a past bot answer (thumbs up / down / clear). Scoped to the owner. */
    public function rate(Request $request): JsonResponse
    {
        if (! SupportBotSettings::enabled()) {
            return response()->json(['ok' => false, 'error' => 'unavailable'], 404);
        }
        $data = $request->validate([
            'log_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'in:-1,0,1'],
        ]);
        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $log = SupportBotLog::query()
            ->where('id', $data['log_id'])
            ->where('user_id', $userId)
            ->first();
        if (! $log) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $log->rating = $data['rating'] === 0 ? null : $data['rating'];
        $log->save();

        return response()->json(['ok' => true, 'rating' => (int) $data['rating']]);
    }

    /**
     * "New chat" — hide the user's previous turns so the widget stops reloading
     * them and starts fresh. Rows are kept (hidden_at stamped), not deleted, so
     * the admin analytics are untouched.
     */
    public function clear(Request $request): JsonResponse
    {
        if (! SupportBotSettings::enabled()) {
            return response()->json(['ok' => false, 'error' => 'unavailable'], 404);
        }
        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        SupportBotLog::query()
            ->where('user_id', $userId)
            ->whereNull('hidden_at')
            ->update(['hidden_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Browsable help-article list (labels + short preview) for the Docs surface. */
    public function articles(): JsonResponse
    {
        if (! SupportBotSettings::enabled()) {
            return response()->json(['ok' => false, 'error' => 'unavailable'], 404);
        }
        $rows = SupportBotSource::query()
            ->where('status', 'ready')
            ->orderBy('label')
            ->limit(200)
            ->get(['id', 'label', 'content'])
            ->map(fn ($s) => [
                'id'      => $s->id,
                'label'   => $s->label,
                'preview' => mb_substr(preg_replace('/\s+/u', ' ', (string) $s->content) ?? '', 0, 160),
            ]);

        return response()->json(['ok' => true, 'articles' => $rows]);
    }
}
