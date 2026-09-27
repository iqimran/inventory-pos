<?php

namespace App\Http\Requests\Catalog;

use App\Models\Subcategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubcategoryRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return Subcategory::class;
    }

    protected function routeParameter(): string
    {
        return 'subcategory';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Subcategory|null $record */
        $record = $this->record();

        return [
            'category_id' => ['required', 'integer', $this->activeOrCurrent('categories', $record?->category_id)],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('subcategories')->where('category_id', $this->integer('category_id'))->ignore($record),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Subcategory|null $record */
                $record = $this->record();

                if ($record && $record->category_id !== $this->integer('category_id') && $record->products()->exists()) {
                    $validator->errors()->add('category_id', 'Products use this subcategory; it cannot be moved to another category.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'This category already has a subcategory with that name.'];
    }
}
