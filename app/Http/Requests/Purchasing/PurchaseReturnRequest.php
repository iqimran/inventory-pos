<?php

namespace App\Http\Requests\Purchasing;

use App\Enums\PaymentMethod;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('return', $this->route('purchase'));
    }

    protected function prepareForValidation(): void
    {
        // Lines left at zero in the form are not part of the return.
        if (is_array($this->input('items'))) {
            $this->merge(['items' => array_values(array_filter(
                $this->input('items'),
                fn ($item) => is_array($item) && (int) ($item['quantity'] ?? 0) !== 0,
            ))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'refund_amount' => MoneyRules::amount(required: false),
            'refund_method' => ['nullable', Rule::requiredIf(fn () => MoneyRules::isPositiveInput($this->input('refund_amount'))), Rule::enum(PaymentMethod::class)],
        ];
    }

    public function messages(): array
    {
        return ['items.required' => 'Enter a quantity for at least one item to return.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function returnData(): array
    {
        $data = $this->validated();
        $data['items'] = array_map(fn (array $item) => [
            'purchase_item_id' => (int) $item['purchase_item_id'],
            'quantity' => (int) $item['quantity'],
        ], $data['items']);

        return $data;
    }
}
