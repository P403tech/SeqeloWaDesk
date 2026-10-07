<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Ring buffer of recent LLM provider failures (missing keys, HTTP 4xx/5xx).
 * In-memory lastProviderError is request-scoped; this lets Admin → AI
 * Dashboard show the last failures across workspaces without a new table.
 */
class AiProviderErrorInbox
{
    public const CACHE_KEY = 'ai:provider_errors';

    public const MAX = 40;

    public static function record(
        string $provider,
        string $message,
        int $workspaceId = 0,
        string $model = '',
    ): void {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        $rows = Cache::get(self::CACHE_KEY, []);
        if (! is_array($rows)) {
            $rows = [];
        }

        array_unshift($rows, [
            'at'           => now()->toIso8601String(),
            'provider'     => strtolower(trim($provider)),
            'model'        => mb_substr($model, 0, 80),
            'workspace_id' => $workspaceId > 0 ? $workspaceId : null,
            'message'      => mb_substr($message, 0, 400),
        ]);

        Cache::put(self::CACHE_KEY, array_slice($rows, 0, self::MAX), now()->addDays(14));
    }

    /** @return list<array{at:string,provider:string,model:string,workspace_id:?int,message:string}> */
    public static function recent(int $limit = 20): array
    {
        $rows = Cache::get(self::CACHE_KEY, []);
        if (! is_array($rows)) {
            return [];
        }

        return array_slice($rows, 0, max(1, $limit));
    }
}
