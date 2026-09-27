<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PurchasesView->value);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $user->can(Permission::PurchasesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PurchasesCreate->value);
    }

    public function return(User $user, Purchase $purchase): bool
    {
        return $user->can(Permission::PurchasesReturn->value);
    }

    public function pay(User $user, Purchase $purchase): bool
    {
        return $user->can(Permission::PaymentsCreate->value);
    }
}
