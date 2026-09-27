<?php

namespace App\Http\Requests\Purchasing;

use App\Enums\PaymentMethod;
use App\Models\Purchase;
use App\Support\Money;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Purchase::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'party_id' => ['required', 'integer', MoneyRules::activeSupplier()],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:64'],
            'discount' => MoneyRules::amount(required: false),
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.MoneyRules::MAX_UNIT],
            'paid_amount' => MoneyRules::amount(required: false),
            'payment_method' => ['nullable', Rule::requiredIf(fn () => MoneyRules::isPositiveInput($this->input('paid_amount'))), Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'apply_advance' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.exists' => 'Select an active supplier.',
            'items.required' => 'Add at least one product.',
            'items.*.product_id.distinct' => 'Each product may appear only once; adjust the quantity instead.',
            'items.*.product_id.exists' => 'The product is not available for purchase.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $subtotal = Money::add(...array_map(
                    fn (array $item) => Money::mul(Money::of((string) $item['unit_cost']), (int) $item['quantity']),
                    $this->input('items'),
                ));
                $discount = Money::of((string) ($this->input('discount') ?? '0'));

                if (Money::cmp($discount, $subtotal) > 0) {
                    $validator->errors()->add('discount', 'The discount cannot exceed the subtotal.');
                }

                if (Money::cmp(Money::of((string) ($this->input('paid_amount') ?? '0')), Money::sub($subtotal, $discount)) > 0) {
                    $validator->errors()->add('paid_amount', 'The paid amount cannot exceed the purchase total.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseData(): array
    {
        $data = $this->validated();
        $data['apply_advance'] = $this->boolean('apply_advance');
        $data['items'] = array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
            'unit_cost' => (string) $item['unit_cost'],
        ], $data['items']);

        return $data;
    }
}
