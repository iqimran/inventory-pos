<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Active products whose stock is at or below their reorder level.
 * Products with a reorder level of 0 are not tracked for low stock.
 */
class LowStockQuery
{
    /**
     * @param  array{q?: ?string, category_id?: ?int, only_out_of_stock?: bool}  $filters
     * @return Builder<Product>
     */
    public function query(array $filters = []): Builder
    {
        $stock = 'COALESCE(product_stocks.quantity, 0)';

        return Product::query()
            ->select('products.*')
            ->selectRaw("{$stock} as stock_quantity")
            ->selectRaw("products.reorder_level - {$stock} as shortfall")
            ->leftJoin('product_stocks', 'product_stocks.product_id', '=', 'products.id')
            ->with(['category:id,name', 'subcategory:id,name', 'brand:id,name', 'unit:id,name,short_name'])
            ->where('products.is_active', true)
            ->where('products.reorder_level', '>', 0)
            ->whereRaw("{$stock} <= products.reorder_level")
            ->when($filters['only_out_of_stock'] ?? false, fn (Builder $q) => $q->whereRaw("{$stock} <= 0"))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('products.category_id', $id))
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function (Builder $q) use ($filters) {
                $term = trim((string) $filters['q']);
                $contains = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("products.name LIKE ? ESCAPE '!'", [$contains])
                    ->orWhere('products.sku', strtoupper($term))
                    ->orWhere('products.barcode', $term));
            })
            ->orderByRaw("{$stock} <= 0 DESC")
            ->orderByDesc('shortfall')
            ->orderBy('products.name');
    }

    public function count(): int
    {
        return $this->query()->reorder()->count();
    }
}
