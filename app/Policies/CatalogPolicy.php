<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared policy for products and their master data (categories, subcategories, brands, units).
 */
class CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProductsView->value);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can(Permission::ProductsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->can(Permission::ProductsManage->value);
    }
}
