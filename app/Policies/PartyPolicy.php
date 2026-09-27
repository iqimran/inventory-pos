<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Party;
use App\Models\User;

class PartyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PartiesView->value);
    }

    public function view(User $user, Party $party): bool
    {
        return $user->can(Permission::PartiesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PartiesManage->value);
    }

    public function update(User $user, Party $party): bool
    {
        return $user->can(Permission::PartiesManage->value);
    }

    public function delete(User $user, Party $party): bool
    {
        return $user->can(Permission::PartiesManage->value);
    }

    public function adjustLedger(User $user, Party $party): bool
    {
        return $user->can(Permission::LedgerAdjust->value);
    }
}
