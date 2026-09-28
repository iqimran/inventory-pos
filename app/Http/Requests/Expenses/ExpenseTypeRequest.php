<?php

namespace App\Http\Requests\Expenses;

use App\Models\ExpenseType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->type();

        return $type ? $this->user()->can('update', $type) : $this->user()->can('create', ExpenseType::class);
    }

    protected function prepareForValidation(): void
    {
        $description = trim((string) $this->input('description'));

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $description === '' ? null : $description,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_types')->ignore($this->type())],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function type(): ?ExpenseType
    {
        $type = $this->route('expenseType');

        return $type instanceof ExpenseType ? $type : null;
    }
}
