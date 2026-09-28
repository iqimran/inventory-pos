<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceJob;
use App\Models\User;

class ServiceJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ServiceView->value);
    }

    public function view(User $user, ServiceJob $job): bool
    {
        return $user->can(Permission::ServiceView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    /**
     * Details, status, parts and charges.
     */
    public function update(User $user, ServiceJob $job): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    /**
     * Bill the job (consumes parts, posts the receivable, takes payment).
     */
    public function invoice(User $user, ServiceJob $job): bool
    {
        return $user->can(Permission::ServiceManage->value);
    }

    /**
     * Charge a part at a price other than its retail price (same permission as the POS).
     */
    public function overridePrice(User $user): bool
    {
        return $user->can(Permission::SalesPriceOverride->value);
    }
}
