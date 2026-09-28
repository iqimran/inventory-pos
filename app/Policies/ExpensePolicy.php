<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ExpensesView->value);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->can(Permission::ExpensesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ExpensesManage->value);
    }

    /**
     * Voided expenses are closed; edits to recorded ones are audited.
     */
    public function update(User $user, Expense $expense): bool
    {
        return ! $expense->isVoid() && $user->can(Permission::ExpensesManage->value);
    }

    /**
     * The only way to remove an expense from totals. Kept for audit.
     */
    public function void(User $user, Expense $expense): bool
    {
        return ! $expense->isVoid() && $user->can(Permission::ExpensesVoid->value);
    }
}
