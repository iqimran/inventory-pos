<?php

namespace App\Http\Requests\Catalog;

use App\Models\Brand;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class BrandRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return Brand::class;
    }

    protected function routeParameter(): string
    {
        return 'brand';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('brands')->ignore($this->record())],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
