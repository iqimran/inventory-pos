<?php

namespace App\Domain\Inventory\Exceptions;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Thrown when a stock-out would take a balance below zero while negative stock is not allowed.
 * Extends ValidationException so web and API callers receive a normal 422 / form error.
 */
class InsufficientStockException extends ValidationException
{
    public static function forProduct(Product $product, int $available, int $requested, string $field = 'quantity'): static
    {
        return static::withMessages([
            $field => "Insufficient stock for {$product->name}: {$available} available, {$requested} requested.",
        ]);
    }
}
