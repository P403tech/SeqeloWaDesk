<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksWorkspaceMembership
{
    protected function inWorkspace(User $user, ?int $workspaceId, ?int $legacyOwnerId = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($workspaceId) {
            return $user->workspaces()->where('workspaces.id', $workspaceId)->exists();
        }

        return $legacyOwnerId !== null && (int) $legacyOwnerId === (int) $user->id;
    }
}
