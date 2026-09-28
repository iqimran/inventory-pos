<?php

namespace App\Http\Requests\Expenses;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->expense();

        return $expense ? $this->user()->can('update', $expense) : $this->user()->can('create', Expense::class);
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['reference', 'notes'] as $field) {
            $value = trim((string) $this->input($field));
            $trimmed[$field] = $value === '' ? null : $value;
        }

        $this->merge($trimmed);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currentType = $this->expense()?->expense_type_id;

        return [
            // Active types only — except the type an edited expense already has.
            'expense_type_id' => ['required', 'integer', Rule::exists('expense_types', 'id')->where(function ($query) use ($currentType) {
                $query->where('is_active', true)->when($currentType, fn ($q) => $q->orWhere('id', $currentType));
            })],
            'amount' => MoneyRules::positive(),
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'expense_type_id.exists' => 'Select an active expense type.',
            'expense_date.before_or_equal' => 'The expense date cannot be in the future.',
        ];
    }

    /**
     * @return array{expense_type_id: int, amount: string, expense_date: string, payment_method: string, reference: ?string, notes: ?string}
     */
    public function expenseData(): array
    {
        $data = $this->validated();

        return [
            'expense_type_id' => (int) $data['expense_type_id'],
            'amount' => (string) $data['amount'],
            'expense_date' => now()->parse($data['expense_date'])->toDateString(),
            'payment_method' => (string) $data['payment_method'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function expense(): ?Expense
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense ? $expense : null;
    }
}
