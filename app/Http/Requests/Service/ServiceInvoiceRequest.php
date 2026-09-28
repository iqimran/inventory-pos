<?php

namespace App\Http\Requests\Service;

use App\Enums\PaymentMethod;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoice', $this->route('serviceJob'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'discount' => MoneyRules::amount(required: false),
            'paid_amount' => MoneyRules::amount(),
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'deliver' => ['boolean'],
        ];
    }

    /**
     * @return array{discount: string, paid_amount: string, payment_method: string, notes: ?string, deliver: bool}
     */
    public function invoiceData(): array
    {
        return [
            'discount' => (string) ($this->validated('discount') ?? '0'),
            'paid_amount' => (string) $this->validated('paid_amount'),
            'payment_method' => (string) $this->validated('payment_method'),
            'notes' => $this->validated('notes'),
            'deliver' => $this->boolean('deliver'),
        ];
    }
}
