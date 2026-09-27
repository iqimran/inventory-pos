<?php

namespace App\Http\Requests\Catalog;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class CategoryRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return Category::class;
    }

    protected function routeParameter(): string
    {
        return 'category';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('categories')->ignore($this->record())],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
