<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileStock extends Command
{
    protected $signature = 'inventory:reconcile {--fix : Rewrite mismatched balances from the stock ledger}';

    protected $description = 'Verify product stock balances against the stock movement ledger';

    public function handle(): int
    {
        $ledger = DB::table('stock_movements')
            ->select('product_id', DB::raw('SUM(quantity) as ledger_quantity'))
            ->groupBy('product_id');

        $mismatches = DB::table('products')
            ->leftJoinSub($ledger, 'ledger', 'ledger.product_id', '=', 'products.id')
            ->leftJoin('product_stocks', 'product_stocks.product_id', '=', 'products.id')
            ->whereRaw('COALESCE(ledger.ledger_quantity, 0) <> COALESCE(product_stocks.quantity, 0)')
            ->orderBy('products.id')
            ->get([
                'products.id',
                'products.sku',
                DB::raw('COALESCE(ledger.ledger_quantity, 0) as ledger_quantity'),
                DB::raw('COALESCE(product_stocks.quantity, 0) as balance_quantity'),
            ]);

        if ($mismatches->isEmpty()) {
            $this->components->info('All stock balances match the stock movement ledger.');

            return self::SUCCESS;
        }

        $this->table(['Product ID', 'SKU', 'Ledger', 'Balance'], $mismatches->map(fn ($row) => [
            $row->id, $row->sku, $row->ledger_quantity, $row->balance_quantity,
        ]));

        if (! $this->option('fix')) {
            $this->components->error("{$mismatches->count()} balance(s) do not match the ledger. Re-run with --fix to repair.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($mismatches): void {
            foreach ($mismatches as $row) {
                DB::table('product_stocks')->updateOrInsert(
                    ['product_id' => $row->id],
                    ['quantity' => (int) $row->ledger_quantity, 'updated_at' => now()],
                );
            }
        });

        $this->components->info("Repaired {$mismatches->count()} balance(s) from the ledger.");

        return self::SUCCESS;
    }
}
