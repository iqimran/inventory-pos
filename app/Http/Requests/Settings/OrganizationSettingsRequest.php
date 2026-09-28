<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OrganizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::SettingsManage->value);
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['name', 'address', 'phone', 'receipt_footer'] as $field) {
            $value = trim((string) $this->input($field));
            $trimmed[$field] = $value === '' ? null : $value;
        }

        $this->merge($trimmed);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:100'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            // Raster images only: an uploaded SVG could carry script and is served from this origin.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048', 'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000'],
            'remove_logo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.mimes' => 'The logo must be a PNG, JPG or WebP image.',
            'logo.mimetypes' => 'The logo must be a PNG, JPG or WebP image.',
            'logo.max' => 'The logo may not be larger than 2 MB.',
            'logo.dimensions' => 'The logo must be between 32×32 and 4000×4000 pixels.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'organization name', 'phone' => 'contact number', 'receipt_footer' => 'invoice footer'];
    }

    /**
     * @return array{name: string, address: ?string, phone: ?string, receipt_footer: ?string}
     */
    public function details(): array
    {
        return $this->safe()->only(['name', 'address', 'phone', 'receipt_footer']);
    }
}
