<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class DevicePolicy
{
    use ChecksWorkspaceMembership;

    public function view(User $user, Device $device): bool
    {
        return $this->inWorkspace(
            $user,
            $device->workspace_id ? (int) $device->workspace_id : null,
            $device->user_id ? (int) $device->user_id : null,
        );
    }

    public function update(User $user, Device $device): bool
    {
        return $this->view($user, $device);
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->view($user, $device);
    }
}
