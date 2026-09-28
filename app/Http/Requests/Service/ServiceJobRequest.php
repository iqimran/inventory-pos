<?php

namespace App\Http\Requests\Service;

use App\Domain\MobileService\TechnicianDirectory;
use App\Domain\Sales\CustomerDirectory;
use App\Models\ServiceJob;
use App\Support\Validation\MoneyRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Job intake (create) and job details (update). On create, either an existing device of the
 * customer (device_id) or a new device (device.*) is given.
 */
class ServiceJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        $job = $this->job();

        return $job ? $this->user()->can('update', $job) : $this->user()->can('create', ServiceJob::class);
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'technician_id' => $this->filled('technician_id') ? $this->input('technician_id') : null,
            'estimated_amount' => $this->filled('estimated_amount') ? $this->input('estimated_amount') : '0',
            'promised_at' => $this->filled('promised_at') ? $this->input('promised_at') : null,
        ];

        if (! $this->job()) {
            $merge['device_id'] = $this->filled('device_id') ? $this->input('device_id') : null;
            $merge['device'] = $merge['device_id'] ? null : DeviceRules::normalize((array) $this->input('device', []));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'technician_id' => ['nullable', 'integer', $this->technicianRule()],
            'complaint' => ['required', 'string', 'max:2000'],
            'estimated_amount' => MoneyRules::amount(),
            'promised_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->job()) {
            return $rules + ['diagnosis' => ['nullable', 'string', 'max:2000']];
        }

        return $rules + [
            'party_id' => [
                'required', 'integer',
                Rule::exists('parties', 'id')->where('is_active', true)->whereIn('type', CustomerDirectory::customerTypes()),
            ],
            'device_id' => ['nullable', 'integer', Rule::exists('devices', 'id')->where('party_id', $this->integer('party_id'))],
            'device' => ['nullable', 'array'],
            ...DeviceRules::rules('device.', fn () => $this->integer('party_id') ?: null, required: $this->input('device_id') === null),
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.exists' => 'Select an active customer.',
            'device_id.exists' => 'The device does not belong to this customer.',
            'device.brand.required' => 'Enter the device brand, or pick one of the customer\'s devices.',
            'device.model.required' => 'Enter the device model.',
            ...DeviceRules::messages('device.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jobData(): array
    {
        $data = $this->validated();

        foreach (['party_id', 'device_id', 'technician_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] !== null ? (int) $data[$field] : null;
            }
        }

        $data['estimated_amount'] = (string) $data['estimated_amount'];

        return $data;
    }

    private function technicianRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! app(TechnicianDirectory::class)->isTechnician((int) $value)) {
                $fail('Select an active technician.');
            }
        };
    }

    private function job(): ?ServiceJob
    {
        $job = $this->route('serviceJob');

        return $job instanceof ServiceJob ? $job : null;
    }
}
