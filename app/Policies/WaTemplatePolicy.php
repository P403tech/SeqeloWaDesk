<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WaTemplate;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class WaTemplatePolicy
{
    use ChecksWorkspaceMembership;

    public function view(User $user, WaTemplate $template): bool
    {
        if ($template->workspace_id === null && $template->user_id === null) {
            return true;
        }

        return $this->inWorkspace(
            $user,
            $template->workspace_id ? (int) $template->workspace_id : null,
            $template->user_id ? (int) $template->user_id : null,
        );
    }

    public function update(User $user, WaTemplate $template): bool
    {
        if ($template->workspace_id === null && $template->user_id === null) {
            return $user->isAdmin();
        }

        return $this->view($user, $template);
    }

    public function delete(User $user, WaTemplate $template): bool
    {
        return $this->update($user, $template);
    }
}
