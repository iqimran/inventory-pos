<?php

namespace App\Http\Requests\Parties;

use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LedgerAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('adjustLedger', $this->route('party'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'side' => ['required', Rule::in(['debit', 'credit'])],
            'amount' => MoneyRules::positive(),
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
