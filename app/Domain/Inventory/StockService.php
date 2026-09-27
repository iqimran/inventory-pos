<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Data\StockMovementData;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single entry point for changing stock.
 *
 * Every change writes an immutable stock_movements row and updates the product's
 * running balance in the same transaction, with the balance row locked so
 * concurrent movements cannot interleave.
 */
class StockService
{
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(private readonly Config $config) {}

    public function record(StockMovementData $data): StockMovement
    {
        return $this->recordMany([$data])->first();
    }

    /**
     * Record several movements atomically (all succeed or none do).
     *
     * @param  iterable<StockMovementData>  $movements
     * @return Collection<int, StockMovement> in the order given
     */
    public function recordMany(iterable $movements): Collection
    {
        $movements = collect($movements)->values();

        // Retried on deadlock/serialization errors when this is the outermost transaction.
        return DB::transaction(function () use ($movements): Collection {
            $productIds = $movements->pluck('productId')->unique()->sort()->values();
            $products = Product::whereKey($productIds)->get()->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw new InvalidArgumentException('Cannot record stock for an unknown product.');
            }

            // Lock balances in a stable order to avoid deadlocks between concurrent multi-line transactions.
            $balances = $this->lockBalances($productIds->all());
            $recorded = collect();

            foreach ($movements as $data) {
                /** @var StockMovementData $data */
                $balance = $balances[$data->productId];
                $newBalance = $balance + $data->signedQuantity();

                if (! $data->type->isInbound() && $newBalance < 0 && ! $this->allowsNegativeStock()) {
                    throw InsufficientStockException::forProduct($products->get($data->productId), $balance, $data->quantity);
                }

                // Later lines for the same product build on this running balance.
                $balances[$data->productId] = $newBalance;

                $recorded->push(StockMovement::create([
                    'product_id' => $data->productId,
                    'type' => $data->type,
                    'quantity' => $data->signedQuantity(),
                    'balance_after' => $newBalance,
                    'unit_cost' => $data->unitCost,
                    'reference_type' => $data->reference?->getMorphClass(),
                    'reference_id' => $data->reference?->getKey(),
                    'reason' => $data->reason,
                    'notes' => $data->notes,
                    'occurred_at' => $data->occurredAt ?? now(),
                    'created_by' => Auth::id(),
                ]));
            }

            foreach ($recorded->groupBy('product_id') as $productId => $productMovements) {
                ProductStock::whereKey($productId)->update(['quantity' => $productMovements->last()->balance_after]);
            }

            return $recorded;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Current balance from the maintained running total.
     */
    public function balance(Product|int $product): int
    {
        $productId = $product instanceof Product ? $product->getKey() : $product;

        return (int) ProductStock::whereKey($productId)->value('quantity');
    }

    /**
     * Balance recomputed from the ledger (the source of truth).
     */
    public function ledgerBalance(Product|int $product): int
    {
        $productId = $product instanceof Product ? $product->getKey() : $product;

        return (int) StockMovement::where('product_id', $productId)->sum('quantity');
    }

    /**
     * Ensure a balance row exists for a newly created product (a zero balance, not a stock change).
     */
    public function initialise(Product $product): void
    {
        DB::table('product_stocks')->insertOrIgnore(['product_id' => $product->getKey(), 'quantity' => 0, 'updated_at' => now()]);
    }

    public function allowsNegativeStock(): bool
    {
        return (bool) $this->config->get('inventory.allow_negative_stock', false);
    }

    /**
     * Lock the balance rows (exclusive) for the given products.
     *
     * Existing rows are locked with SELECT ... FOR UPDATE before anything is inserted:
     * an INSERT IGNORE against an existing key would take a shared lock first and
     * deadlock when concurrent transactions then upgrade to exclusive locks.
     *
     * @param  list<int>  $productIds  sorted ascending
     * @return array<int, int> product id => locked balance
     */
    private function lockBalances(array $productIds): array
    {
        $balances = $this->selectForUpdate($productIds);
        $missing = array_values(array_diff($productIds, array_keys($balances)));

        if ($missing !== []) {
            DB::table('product_stocks')->insertOrIgnore(array_map(
                fn (int $id) => ['product_id' => $id, 'quantity' => 0, 'updated_at' => now()],
                $missing,
            ));

            $balances += $this->selectForUpdate($missing);
        }

        return $balances;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function selectForUpdate(array $productIds): array
    {
        return ProductStock::whereKey($productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->pluck('quantity', 'product_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->all();
    }
}
