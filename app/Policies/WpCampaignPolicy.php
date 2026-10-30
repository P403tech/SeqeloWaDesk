<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WpCampaign;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class WpCampaignPolicy
{
    use ChecksWorkspaceMembership;

    public function view(User $user, WpCampaign $campaign): bool
    {
        return $this->inWorkspace(
            $user,
            $campaign->workspace_id ? (int) $campaign->workspace_id : null,
            $campaign->created_by ? (int) $campaign->created_by : null,
        );
    }

    public function update(User $user, WpCampaign $campaign): bool
    {
        return $this->view($user, $campaign);
    }

    public function delete(User $user, WpCampaign $campaign): bool
    {
        return $this->view($user, $campaign);
    }
}
