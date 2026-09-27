<?php

namespace App\Http\Requests\Purchasing;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates both purchase payments and supplier advances.
 */
class SupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Payment::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'party_id' => ['required', 'integer', MoneyRules::activeSupplier()],
            'purchase_id' => ['nullable', 'integer', Rule::exists('purchases', 'id')->where('party_id', $this->integer('party_id'))],
            'amount' => MoneyRules::positive(),
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.exists' => 'Select an active supplier.',
            'purchase_id.exists' => 'The purchase does not belong to this supplier.',
        ];
    }
}
