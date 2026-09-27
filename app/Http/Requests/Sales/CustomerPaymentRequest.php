<?php

namespace App\Http\Requests\Sales;

use App\Domain\Sales\CustomerDirectory;
use App\Enums\PaymentMethod;
use App\Models\Sale;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('collect', Sale::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->whereIn('type', CustomerDirectory::customerTypes())],
            'sale_id' => ['nullable', 'integer', Rule::exists('sales', 'id')->where('party_id', $this->integer('party_id'))],
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
            'party_id.exists' => 'Select a customer.',
            'sale_id.exists' => 'The sale does not belong to this customer.',
        ];
    }
}
