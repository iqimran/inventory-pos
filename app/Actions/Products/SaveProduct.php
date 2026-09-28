<?php

namespace App\Actions\Products;

use App\Domain\Audit\AuditTrail;
use App\Domain\Inventory\StockService;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates product master data. Never changes stock:
 * stock is only changed through StockService movements.
 */
class SaveProduct
{
    /** Master data whose changes are audited (prices in particular). */
    public const AUDITED = ['name', 'sku', 'barcode', 'category_id', 'subcategory_id', 'brand_id', 'unit_id',
        'purchase_price', 'retail_price', 'wholesale_price', 'reorder_level', 'is_active'];

    public function __construct(private readonly StockService $stock, private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated product attributes
     */
    public function handle(?Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $creating = $product === null;
            $product ??= new Product;
            $before = $creating ? [] : $this->audit->snapshot($product, self::AUDITED);
            $product->fill($data)->save();

            if ($creating) {
                $this->stock->initialise($product);
                $this->audit->record('product.created', $product, new: $this->audit->snapshot($product, self::AUDITED), description: $product->name);
            } else {
                $this->audit->recordChanges('product.updated', $product, $before, $this->audit->snapshot($product, self::AUDITED), $product->name);
            }

            return $product;
        });
    }
}
