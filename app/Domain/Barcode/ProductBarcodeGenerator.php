<?php

namespace App\Domain\Barcode;

use App\Models\Product;

/**
 * Internal product barcodes: 12 digits starting with "20" (the GS1 range reserved for in-store use,
 * so they never clash with manufacturer EAN/UPC codes) followed by the zero-padded product id.
 * All-digit, even-length values print compactly in Code 128 (subset C).
 */
class ProductBarcodeGenerator
{
    public const PREFIX = '20';

    public function for(Product $product): string
    {
        $candidate = self::PREFIX.str_pad((string) $product->getKey(), 10, '0', STR_PAD_LEFT);

        // A manually entered barcode could already use the value; fall back to a free random in-store code.
        while ($this->taken($candidate, $product)) {
            $candidate = self::PREFIX.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    private function taken(string $barcode, Product $product): bool
    {
        return Product::where('barcode', $barcode)->whereKeyNot($product->getKey())->exists();
    }
}
