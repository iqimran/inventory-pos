<?php

namespace App\Domain\Inventory;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Product lookup used by the catalogue screens and the API.
 *
 * A search term matches barcode or SKU exactly (unique indexes) or the name partially;
 * exact code matches are ranked first so a scanned barcode is always the top result.
 */
class ProductSearch
{
    /** Relations needed to render a product row without N+1 queries. */
    public const LIST_RELATIONS = ['category:id,name', 'subcategory:id,name', 'brand:id,name', 'unit:id,name,short_name', 'stock'];

    /**
     * @param  array{q?: ?string, category_id?: ?int, subcategory_id?: ?int, brand_id?: ?int, status?: ?string}  $filters
     *                                                                                                                     status: 'active' | 'inactive' | null (all)
     * @return Builder<Product>
     */
    public function query(array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return Product::query()
            ->with(self::LIST_RELATIONS)
            ->when($term !== '', function (Builder $query) use ($term) {
                $contains = '%'.$this->escapeLike($term).'%';
                $prefix = $this->escapeLike($term).'%';

                $query->where(function (Builder $match) use ($term, $contains) {
                    $match->where('barcode', $term)
                        ->orWhere('sku', strtoupper($term))
                        ->orWhereRaw("name LIKE ? ESCAPE '!'", [$contains]);
                })->orderByRaw(
                    "CASE WHEN barcode = ? OR sku = ? THEN 0 WHEN name LIKE ? ESCAPE '!' THEN 1 ELSE 2 END",
                    [$term, strtoupper($term), $prefix],
                );
            })
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['subcategory_id'] ?? null, fn (Builder $q, $id) => $q->where('subcategory_id', $id))
            ->when($filters['brand_id'] ?? null, fn (Builder $q, $id) => $q->where('brand_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @param  array{q?: ?string, category_id?: ?int, subcategory_id?: ?int, brand_id?: ?int, status?: ?string}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * Exact lookup by barcode, then SKU (e.g. from a barcode scanner).
     */
    public function findByCode(string $code, bool $activeOnly = true): ?Product
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $base = Product::query()->with(self::LIST_RELATIONS)->when($activeOnly, fn (Builder $q) => $q->active());

        return (clone $base)->where('barcode', $code)->first()
            ?? (clone $base)->where('sku', strtoupper($code))->first();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
