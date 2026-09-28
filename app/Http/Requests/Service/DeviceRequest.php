<?php

namespace App\Http\Requests\Service;

use App\Domain\Sales\CustomerDirectory;
use App\Models\Device;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->device();

        return $device ? $this->user()->can('update', $device) : $this->user()->can('create', Device::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(DeviceRules::normalize($this->all()));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $device = $this->device();

        return [
            // The owner is fixed once registered.
            'party_id' => $device ? ['prohibited'] : [
                'required', 'integer',
                Rule::exists('parties', 'id')->where('is_active', true)->whereIn('type', CustomerDirectory::customerTypes()),
            ],
            ...DeviceRules::rules('', fn () => $device?->party_id ?? $this->integer('party_id'), $device),
        ];
    }

    public function messages(): array
    {
        return ['party_id.exists' => 'Select an active customer.', ...DeviceRules::messages('')];
    }

    private function device(): ?Device
    {
        $device = $this->route('device');

        return $device instanceof Device ? $device : null;
    }
}
