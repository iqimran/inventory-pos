<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Product::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:191'],
            'category_id' => ['nullable', 'integer'],
            'subcategory_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{q: string, category_id: ?int, subcategory_id: ?int, brand_id: ?int, status: ?string}
     */
    public function filters(?string $defaultStatus = null): array
    {
        return [
            'q' => trim((string) $this->validated('q', '')),
            'category_id' => $this->validated('category_id') ? (int) $this->validated('category_id') : null,
            'subcategory_id' => $this->validated('subcategory_id') ? (int) $this->validated('subcategory_id') : null,
            'brand_id' => $this->validated('brand_id') ? (int) $this->validated('brand_id') : null,
            'status' => $this->validated('status') ?? $defaultStatus,
        ];
    }
}
