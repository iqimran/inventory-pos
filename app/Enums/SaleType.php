<?php

namespace App\Enums;

use App\Models\Product;

enum SaleType: string
{
    case Retail = 'RETAIL';
    case Wholesale = 'WHOLESALE';

    public function label(): string
    {
        return match ($this) {
            self::Retail => 'Retail',
            self::Wholesale => 'Wholesale',
        };
    }

    /**
     * The product's list price for this sale mode.
     */
    public function listPrice(Product $product): string
    {
        return match ($this) {
            self::Retail => $product->retail_price,
            self::Wholesale => $product->wholesale_price,
        };
    }
}
