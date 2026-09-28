<?php

namespace App\Http\Requests\Service;

use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ServiceJobChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('serviceJob'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => MoneyRules::positive(),
        ];
    }

    /**
     * @return array{description: string, amount: string}
     */
    public function chargeData(): array
    {
        return ['description' => (string) $this->validated('description'), 'amount' => (string) $this->validated('amount')];
    }
}
