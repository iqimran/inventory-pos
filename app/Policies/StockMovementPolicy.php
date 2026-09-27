<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::InventoryView->value);
    }

    /**
     * Manual adjustments. Other movement types are created by their own workflows.
     */
    public function adjust(User $user): bool
    {
        return $user->can(Permission::InventoryAdjust->value);
    }
}
