<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExpenseType;
use App\Models\User;

class ExpenseTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ExpensesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ExpensesManage->value);
    }

    public function update(User $user, ExpenseType $type): bool
    {
        return $user->can(Permission::ExpensesManage->value);
    }

    public function delete(User $user, ExpenseType $type): bool
    {
        return $user->can(Permission::ExpensesManage->value);
    }
}
