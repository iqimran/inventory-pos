<?php

namespace App\Domain\Inventory;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\Unit;

/**
 * Master data offered by product forms: active records, plus the product's current
 * (possibly inactive) values so records assigned to a since-deactivated parent can still be edited.
 */
final class ProductFormOptions
{
    /**
     * @return array<string, mixed>
     */
    public function for(?Product $product = null): array
    {
        $options = fn (string $model, ?int $current, array $columns) => $model::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('id', $current)))
            ->orderBy('name')
            ->get($columns);

        return [
            'categories' => $options(Category::class, $product?->category_id, ['id', 'name']),
            'subcategories' => $options(Subcategory::class, $product?->subcategory_id, ['id', 'category_id', 'name']),
            'brands' => $options(Brand::class, $product?->brand_id, ['id', 'name']),
            'units' => $options(Unit::class, $product?->unit_id, ['id', 'name', 'short_name']),
        ];
    }
}
