<?php

namespace App\Http\Requests\Parties;

use App\Enums\OpeningBalanceType;
use App\Enums\PartyType;
use App\Models\Party;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $party = $this->route('party');

        return $party instanceof Party ? $this->user()->can('update', $party) : $this->user()->can('create', Party::class);
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['name', 'phone', 'email', 'address', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim($this->input($field)) === '' && $field !== 'name' ? null : trim($this->input($field));
            }
        }

        // Phones are stored as digits only so lookups (e.g. POS customer search) match however they were typed.
        if (is_string($this->input('phone'))) {
            $clean['phone'] = preg_replace('/\D+/', '', $this->input('phone')) ?: null;
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = ! $this->route('party') instanceof Party;

        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(PartyType::class)],
            'phone' => ['nullable', 'string', 'min:6', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];

        // The opening balance is posted to the ledger once, at creation; later changes are ledger adjustments.
        if ($creating) {
            $rules['opening_balance'] = MoneyRules::amount(required: false);
            $rules['opening_balance_type'] = [
                Rule::requiredIf(fn () => MoneyRules::isPositiveInput($this->input('opening_balance'))),
                'nullable',
                Rule::enum(OpeningBalanceType::class),
            ];
        }

        return $rules;
    }
}
