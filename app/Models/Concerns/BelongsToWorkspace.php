<?php

namespace App\Models\Concerns;

use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global tenant scope. Applies only while WorkspaceContext is bound (an
 * authenticated tenant request). Unset context — jobs, webhooks, artisan,
 * admin — is left alone so those callers keep their explicit workspace filters.
 *
 * Legacy rows with a NULL workspace_id stay visible to the user who owns
 * them, except during impersonation, where that fallback would show the
 * admin's own orphan rows inside the client's workspace.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $q) {
            $wsId = WorkspaceContext::id();
            if ($wsId === null) {
                return;
            }

            $owner = $q->getModel()->workspaceLegacyOwnerColumn();
            $userId = WorkspaceContext::userId();

            $shareUnowned = $q->getModel()->workspaceSharesUnownedRows();

            $q->where(function (Builder $qq) use ($wsId, $owner, $userId, $shareUnowned) {
                $qq->where($qq->getModel()->getTable().'.workspace_id', $wsId);
                if ($owner && $userId && ! WorkspaceContext::impersonating()) {
                    $qq->orWhere(function (Builder $qqq) use ($owner, $userId) {
                        $table = $qqq->getModel()->getTable();
                        $qqq->whereNull($table.'.workspace_id')->where($table.'.'.$owner, $userId);
                    });
                }
                if ($shareUnowned) {
                    $qq->orWhere(function (Builder $qqq) {
                        $table = $qqq->getModel()->getTable();
                        $qqq->whereNull($table.'.workspace_id')->whereNull($table.'.user_id');
                    });
                }
            });
        });
    }

    /**
     * Admin-seeded rows (workspace_id and user_id both NULL) stay visible
     * in every workspace. WaTemplate uses this for platform templates.
     */
    public function workspaceSharesUnownedRows(): bool
    {
        return false;
    }

    /**
     * Column that owns pre-migration rows whose workspace_id is still NULL.
     * Null skips that fallback (workspace_id match only).
     */
    public function workspaceLegacyOwnerColumn(): ?string
    {
        return 'user_id';
    }
}
