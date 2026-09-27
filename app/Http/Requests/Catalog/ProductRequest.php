<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ProductRequest extends CatalogRequest
{
    private const MAX_MONEY = '9999999999999.99';

    protected function modelClass(): string
    {
        return Product::class;
    }

    protected function routeParameter(): string
    {
        return 'product';
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([
            'sku' => is_string($this->input('sku')) ? strtoupper(trim($this->input('sku'))) : $this->input('sku'),
            'barcode' => is_string($this->input('barcode')) && trim($this->input('barcode')) !== '' ? trim($this->input('barcode')) : null,
            'subcategory_id' => $this->filled('subcategory_id') ? $this->input('subcategory_id') : null,
            'brand_id' => $this->filled('brand_id') ? $this->input('brand_id') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->record();
        $money = ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_MONEY];

        return [
            'category_id' => ['required', 'integer', $this->activeOrCurrent('categories', $product?->category_id)],
            'subcategory_id' => [
                'nullable', 'integer',
                $this->activeOrCurrent('subcategories', $product?->subcategory_id)->where('category_id', $this->integer('category_id')),
            ],
            'brand_id' => ['nullable', 'integer', $this->activeOrCurrent('brands', $product?->brand_id)],
            'unit_id' => ['required', 'integer', $this->activeOrCurrent('units', $product?->unit_id)],
            'name' => ['required', 'string', 'max:191'],
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9\-_.\/]*$/', Rule::unique('products')->ignore($product)],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-.]+$/', Rule::unique('products')->ignore($product)],
            'description' => ['nullable', 'string', 'max:1000'],
            'purchase_price' => $money,
            'retail_price' => $money,
            'wholesale_price' => $money,
            'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'subcategory_id.exists' => 'The selected subcategory does not belong to the selected category or is inactive.',
            'sku.regex' => 'The SKU may contain only letters, numbers and - _ . / characters.',
            'barcode.regex' => 'The barcode may contain only letters, numbers, dashes and dots.',
        ];
    }
}
