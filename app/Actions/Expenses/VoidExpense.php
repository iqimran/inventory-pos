<?php

namespace App\Actions\Expenses;

use App\Enums\ExpenseAuditAction;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids an expense: it stays on record (with who, when and why) but no longer counts in totals.
 * This replaces deletion for financial records.
 */
class VoidExpense
{
    /**
     * @throws ValidationException
     */
    public function handle(Expense $expense, string $reason): Expense
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for voiding the expense.']);
        }

        return DB::transaction(function () use ($expense, $reason): Expense {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->isVoid()) {
                throw ValidationException::withMessages(['expense' => "Expense {$expense->expense_no} is already void."]);
            }

            $expense->update([
                'status' => ExpenseStatus::Void,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ]);

            $expense->audits()->create(['action' => ExpenseAuditAction::Voided, 'reason' => $reason, 'created_by' => Auth::id()]);

            return $expense;
        }, 3);
    }
}
