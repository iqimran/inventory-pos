<?php

namespace App\Http\Requests\Sales;

use App\Domain\Sales\CustomerDirectory;
use App\Enums\PaymentMethod;
use App\Enums\SaleType;
use App\Models\Sale;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Sale::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'party_id' => $this->filled('party_id') ? $this->input('party_id') : null,
            'tendered_amount' => $this->filled('tendered_amount') ? $this->input('tendered_amount') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sale_type' => ['required', Rule::enum(SaleType::class)],
            'party_id' => [
                'nullable', 'integer',
                Rule::exists('parties', 'id')->where('is_active', true)->whereIn('type', CustomerDirectory::customerTypes()),
            ],
            // A sale needs at least one product or one service charge.
            'items' => ['required_without:services', 'array', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.MoneyRules::MAX_UNIT],
            'items.*.discount' => MoneyRules::amount(required: false),
            'services' => ['nullable', 'array', 'max:20'],
            'services.*.description' => ['required', 'string', 'max:191'],
            'services.*.amount' => MoneyRules::positive(),
            'discount' => MoneyRules::amount(required: false),
            'paid_amount' => MoneyRules::amount(required: true),
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'tendered_amount' => MoneyRules::amount(required: false),
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.exists' => 'Select an active customer.',
            'items.required_without' => 'The cart is empty.',
            'services.*.description.required' => 'Describe the service.',
            'items.*.product_id.distinct' => 'Each product may appear only once in the cart.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saleData(): array
    {
        $data = $this->validated();
        $data['party_id'] = $data['party_id'] ? (int) $data['party_id'] : null;
        $data['services'] = array_map(fn (array $service) => [
            'description' => trim((string) $service['description']),
            'amount' => (string) $service['amount'],
        ], $data['services'] ?? []);
        $data['items'] = array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => isset($item['unit_price']) ? (string) $item['unit_price'] : null,
            'discount' => isset($item['discount']) ? (string) $item['discount'] : null,
        ], $data['items'] ?? []);

        foreach (['discount', 'paid_amount', 'tendered_amount'] as $field) {
            $data[$field] = isset($data[$field]) ? (string) $data[$field] : null;
        }

        return $data;
    }
}
