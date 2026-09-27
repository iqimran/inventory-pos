<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SalesView->value);
    }

    public function view(User $user, Sale $sale): bool
    {
        return $user->can(Permission::SalesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SalesCreate->value);
    }

    public function overridePrice(User $user): bool
    {
        return $user->can(Permission::SalesPriceOverride->value);
    }

    public function return(User $user, Sale $sale): bool
    {
        return $user->can(Permission::ReturnsCreate->value);
    }

    public function collect(User $user): bool
    {
        return $user->can(Permission::SalesCollect->value);
    }
}
