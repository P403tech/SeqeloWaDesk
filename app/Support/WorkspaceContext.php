<?php

namespace App\Support;

/**
 * Request-scoped tenant pin. When set, BelongsToWorkspace hides every other
 * workspace's rows. Left unset for console, queue workers, and webhooks,
 * which already name the workspace they are working on.
 */
class WorkspaceContext
{
    private static ?int $id = null;

    private static ?int $userId = null;

    private static bool $impersonating = false;

    public static function set(int $workspaceId, ?int $userId = null, bool $impersonating = false): void
    {
        self::$id = $workspaceId > 0 ? $workspaceId : null;
        self::$userId = $userId && $userId > 0 ? $userId : null;
        self::$impersonating = $impersonating;
    }

    public static function clear(): void
    {
        self::$id = null;
        self::$userId = null;
        self::$impersonating = false;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function userId(): ?int
    {
        return self::$userId;
    }

    public static function impersonating(): bool
    {
        return self::$impersonating;
    }
}
