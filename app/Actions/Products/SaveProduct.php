<?php

namespace App\Actions\Products;

use App\Domain\Inventory\StockService;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates product master data. Never changes stock:
 * stock is only changed through StockService movements.
 */
class SaveProduct
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  array<string, mixed>  $data  validated product attributes
     */
    public function handle(?Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $creating = $product === null;
            $product ??= new Product;
            $product->fill($data)->save();

            if ($creating) {
                $this->stock->initialise($product);
            }

            return $product;
        });
    }
}
