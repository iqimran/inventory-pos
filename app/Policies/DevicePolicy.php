<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ServiceView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    public function update(User $user, Device $device): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }
}
