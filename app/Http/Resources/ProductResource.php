<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'subcategory_id' => $this->subcategory_id,
            'brand_id' => $this->brand_id,
            'unit_id' => $this->unit_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'subcategory' => $this->whenLoaded('subcategory', fn () => $this->subcategory?->name),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand?->name),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->short_name),
            // Money is serialised as decimal strings to avoid floating point rounding.
            'purchase_price' => $this->purchase_price,
            'retail_price' => $this->retail_price,
            'wholesale_price' => $this->wholesale_price,
            'reorder_level' => $this->reorder_level,
            'is_active' => $this->is_active,
            'stock' => $this->whenLoaded('stock', fn () => $this->stockQuantity()),
            'is_low_stock' => $this->whenLoaded('stock', fn () => $this->reorder_level > 0 && $this->stockQuantity() <= $this->reorder_level),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
