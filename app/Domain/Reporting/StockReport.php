<?php

namespace App\Domain\Reporting;

use App\Enums\StockMovementType;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock from the stock ledger: current stock and value (product_stocks, the running balance of
 * stock_movements) and per-product movement summaries for a period (opening / in / out / closing).
 */
class StockReport
{
    /**
     * @param  array{q?: ?string, category_id?: ?int, status?: ?string}  $filters  status: low | out | in_stock
     * @return array{products: int, units: int, value: string, low: int, out: int}
     */
    public function totals(array $filters = []): array
    {
        $row = $this->stock($filters)
            ->selectRaw('COUNT(*) as products,
                COALESCE(SUM(CASE WHEN COALESCE(product_stocks.quantity, 0) > 0 THEN product_stocks.quantity ELSE 0 END), 0) as units,
                COALESCE(SUM(CASE WHEN COALESCE(product_stocks.quantity, 0) > 0 THEN product_stocks.quantity * COALESCE(product_stocks.average_cost, products.purchase_price) ELSE 0 END), 0) as value,
                COALESCE(SUM(CASE WHEN products.reorder_level > 0 AND COALESCE(product_stocks.quantity, 0) <= products.reorder_level THEN 1 ELSE 0 END), 0) as low,
                COALESCE(SUM(CASE WHEN COALESCE(product_stocks.quantity, 0) <= 0 THEN 1 ELSE 0 END), 0) as out_of_stock')
            ->first();

        return [
            'products' => (int) $row->products,
            'units' => (int) $row->units,
            'value' => Money::of((string) $row->value),
            'low' => (int) $row->low,
            'out' => (int) $row->out_of_stock,
        ];
    }

    /**
     * @param  array{q?: ?string, category_id?: ?int, status?: ?string}  $filters
     */
    public function products(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->stock($filters)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->select([
                'products.id', 'products.name', 'products.sku', 'products.reorder_level', 'products.retail_price',
                'categories.name as category', 'units.short_name as unit',
            ])
            ->selectRaw('COALESCE(product_stocks.quantity, 0) as quantity, COALESCE(product_stocks.average_cost, products.purchase_price) as unit_cost')
            ->orderBy('products.name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'sku' => $r->sku,
                'category' => $r->category,
                'unit' => $r->unit,
                'quantity' => (int) $r->quantity,
                'reorder_level' => (int) $r->reorder_level,
                'is_low' => $r->reorder_level > 0 && (int) $r->quantity <= (int) $r->reorder_level,
                'unit_cost' => Money::of((string) $r->unit_cost),
                'value' => (int) $r->quantity > 0 ? Money::mul(Money::of((string) $r->unit_cost), (int) $r->quantity) : '0.00',
                'retail_price' => Money::of((string) $r->retail_price),
            ]);
    }

    /**
     * Movement totals per type in the period.
     *
     * @return list<array{type: string, label: string, movements: int, quantity: int}>
     */
    public function movementsByType(ReportPeriod $period): array
    {
        return DB::table('stock_movements')
            ->whereBetween('occurred_at', $period->datetimeBounds())
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as movements, SUM(quantity) as quantity')
            ->orderBy('type')
            ->get()
            ->map(fn ($r) => [
                'type' => $r->type,
                'label' => StockMovementType::tryFrom($r->type)?->label() ?? $r->type,
                'movements' => (int) $r->movements,
                'quantity' => (int) $r->quantity,
            ])
            ->all();
    }

    /**
     * Opening balance, stock in, stock out and closing balance per product for the period,
     * all derived from stock_movements. Products without movements in or before the period are omitted.
     */
    public function movementSummary(ReportPeriod $period, ?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        [$start, $end] = $period->datetimeBounds();

        $query = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.occurred_at', '<=', $end)
            ->when($this->term($search), fn (Builder $q, string $term) => $this->search($q, $term))
            ->groupBy('stock_movements.product_id', 'products.name', 'products.sku')
            ->select(['stock_movements.product_id', 'products.name', 'products.sku'])
            ->selectRaw('SUM(CASE WHEN stock_movements.occurred_at < ? THEN stock_movements.quantity ELSE 0 END) as opening', [$start])
            ->selectRaw('SUM(CASE WHEN stock_movements.occurred_at >= ? AND stock_movements.quantity > 0 THEN stock_movements.quantity ELSE 0 END) as stock_in', [$start])
            ->selectRaw('SUM(CASE WHEN stock_movements.occurred_at >= ? AND stock_movements.quantity < 0 THEN -stock_movements.quantity ELSE 0 END) as stock_out', [$start])
            ->selectRaw('SUM(stock_movements.quantity) as closing')
            ->orderBy('products.name');

        // One row per product: run the aggregate once instead of once more for the count.
        return AggregatePaginator::paginate($query, $perPage, 'movements_page', fn ($r) => [
            'product_id' => (int) $r->product_id,
            'name' => $r->name,
            'sku' => $r->sku,
            'opening' => (int) $r->opening,
            'in' => (int) $r->stock_in,
            'out' => (int) $r->stock_out,
            'closing' => (int) $r->closing,
        ]);
    }

    /**
     * @param  array{q?: ?string, category_id?: ?int, status?: ?string}  $filters
     */
    private function stock(array $filters): Builder
    {
        $quantity = 'COALESCE(product_stocks.quantity, 0)';

        return DB::table('products')
            ->leftJoin('product_stocks', 'product_stocks.product_id', '=', 'products.id')
            ->where('products.is_active', true)
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('products.category_id', $id))
            ->when($this->term($filters['q'] ?? null), fn (Builder $q, string $term) => $this->search($q, $term))
            ->when(($filters['status'] ?? null) === 'low', fn (Builder $q) => $q->where('products.reorder_level', '>', 0)->whereRaw("{$quantity} <= products.reorder_level"))
            ->when(($filters['status'] ?? null) === 'out', fn (Builder $q) => $q->whereRaw("{$quantity} <= 0"))
            ->when(($filters['status'] ?? null) === 'in_stock', fn (Builder $q) => $q->whereRaw("{$quantity} > 0"));
    }

    private function term(?string $search): ?string
    {
        $term = trim((string) $search);

        return $term === '' ? null : $term;
    }

    private function search(Builder $query, string $term): void
    {
        $contains = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(fn (Builder $w) => $w->whereRaw("products.name LIKE ? ESCAPE '!'", [$contains])
            ->orWhere('products.sku', strtoupper($term))
            ->orWhere('products.barcode', $term));
    }
}
