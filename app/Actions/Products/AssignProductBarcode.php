<?php

namespace App\Actions\Products;

use App\Domain\Barcode\ProductBarcodeGenerator;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a product without a barcode an internal Code 128 barcode. An existing barcode is never replaced.
 */
class AssignProductBarcode
{
    public function __construct(private readonly ProductBarcodeGenerator $generator) {}

    /**
     * @throws ValidationException when the product already has a barcode
     */
    public function handle(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            if ($product->barcode !== null) {
                throw ValidationException::withMessages(['barcode' => "{$product->name} already has barcode {$product->barcode}."]);
            }

            $product->forceFill(['barcode' => $this->generator->for($product)])->save();

            return $product;
        });
    }
}
