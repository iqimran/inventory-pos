<?php

namespace App\Http\Requests\Sales;

use App\Enums\Permission;
use App\Models\Sale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Minimal customer creation from the POS counter or service intake (name + phone).
 */
class QuickCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Sale::class) || $this->user()->can(Permission::PartiesManage->value)
            || $this->user()->can(Permission::ServiceManage->value);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => preg_replace('/\D+/', '', (string) $this->input('phone')) ?: null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'min:6', 'max:20', 'unique:parties,phone'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['phone.unique' => 'A party with this phone number already exists — search for it instead.'];
    }
}
