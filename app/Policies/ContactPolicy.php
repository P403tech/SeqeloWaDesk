<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class ContactPolicy
{
    use ChecksWorkspaceMembership;

    public function view(User $user, Contact $contact): bool
    {
        return $this->inWorkspace(
            $user,
            $contact->workspace_id ? (int) $contact->workspace_id : null,
            $contact->user_id ? (int) $contact->user_id : null,
        );
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->view($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $this->view($user, $contact);
    }
}
