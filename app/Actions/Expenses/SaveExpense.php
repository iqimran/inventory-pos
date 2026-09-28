<?php

namespace App\Actions\Expenses;

use App\Enums\ExpenseAuditAction;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a new expense or edits a recorded one. Every create and every effective edit is written
 * to expense_audits (edits with before → after values). Voided expenses cannot be edited.
 */
class SaveExpense
{
    /** Fields whose changes are audited. */
    private const AUDITED = ['expense_type_id', 'amount', 'expense_date', 'payment_method', 'reference', 'notes'];

    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * @param  array{expense_type_id: int, amount: string, expense_date: string, payment_method: string, reference?: ?string, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(?Expense $expense, array $data): Expense
    {
        return DB::transaction(function () use ($expense, $data): Expense {
            $attributes = [
                'expense_type_id' => (int) $data['expense_type_id'],
                'amount' => Money::of($data['amount']),
                'expense_date' => $data['expense_date'],
                'payment_method' => PaymentMethod::from($data['payment_method']),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if (! $expense) {
                $expense = Expense::create([
                    'expense_no' => $this->numbers->next('EXP', now()->parse($attributes['expense_date'])),
                    'status' => ExpenseStatus::Recorded,
                    ...$attributes,
                ]);

                $expense->audits()->create(['action' => ExpenseAuditAction::Created, 'changes' => null, 'created_by' => Auth::id()]);

                return $expense;
            }

            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->isVoid()) {
                throw ValidationException::withMessages(['expense' => "Expense {$expense->expense_no} is void and can no longer be edited."]);
            }

            $before = $this->snapshot($expense);
            $expense->fill($attributes);
            $after = $this->snapshot($expense);
            $changes = [];

            foreach (self::AUDITED as $field) {
                if ($before[$field] !== $after[$field]) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
                }
            }

            if ($changes !== []) {
                $expense->save();
                $expense->audits()->create(['action' => ExpenseAuditAction::Updated, 'changes' => $changes, 'created_by' => Auth::id()]);
            }

            return $expense;
        }, 3);
    }

    /**
     * Comparable string values of the audited fields.
     *
     * @return array<string, ?string>
     */
    private function snapshot(Expense $expense): array
    {
        return [
            'expense_type_id' => (string) $expense->expense_type_id,
            'amount' => Money::of((string) $expense->amount),
            'expense_date' => $expense->expense_date?->toDateString(),
            'payment_method' => $expense->payment_method?->value,
            'reference' => $expense->reference,
            'notes' => $expense->notes,
        ];
    }
}
