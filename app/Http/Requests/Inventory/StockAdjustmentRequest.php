<?php

namespace App\Http\Requests\Inventory;

use App\Enums\AdjustmentReason;
use App\Models\StockMovement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('adjust', StockMovement::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes')) ?: null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'notes' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => $this->input('reason') === AdjustmentReason::Other->value)],
        ];
    }

    public function messages(): array
    {
        return ['notes.required' => 'Please describe the reason for this adjustment.'];
    }
}
