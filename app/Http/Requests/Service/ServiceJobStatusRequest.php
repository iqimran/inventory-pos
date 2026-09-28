<?php

namespace App\Http\Requests\Service;

use App\Enums\ServiceJobStatus;
use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceJobStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('serviceJob'));
    }

    /**
     * Shape only; the workflow guards live in ChangeServiceJobStatus.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ServiceJobStatus::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'estimated_amount' => MoneyRules::amount(required: false),
            'approved_amount' => MoneyRules::amount(required: false),
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, ?string>
     */
    public function transitionData(): array
    {
        return array_map(fn ($value) => $value === null ? null : (string) $value, $this->safe()->except('status'));
    }
}
