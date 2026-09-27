<?php

namespace App\Domain\Purchasing;

use App\Models\PurchaseItem;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure money calculations for purchases and purchase returns.
 */
class PurchaseCalculator
{
    /**
     * Totals for purchase lines, spreading the header discount across lines in proportion to
     * their value (largest-remainder method, so shares sum exactly and are never negative).
     *
     * @param  list<array{quantity: int, unit_cost: string}>  $lines
     * @return array{subtotal: string, discount: string, total: string, lines: list<array{line_subtotal: string, discount_share: string, line_total: string}>}
     */
    public function totals(array $lines, string $discount): array
    {
        $discount = Money::of($discount);
        $lineSubtotals = array_map(fn (array $line) => Money::mul(Money::of($line['unit_cost']), $line['quantity']), $lines);
        $subtotal = Money::add(...$lineSubtotals);

        if (Money::isNegative($discount) || Money::cmp($discount, $subtotal) > 0) {
            throw new InvalidArgumentException('Discount must be between zero and the subtotal.');
        }

        $shares = Money::allocate($discount, $lineSubtotals);
        $computed = [];

        foreach ($lineSubtotals as $index => $lineSubtotal) {
            $computed[] = [
                'line_subtotal' => $lineSubtotal,
                'discount_share' => $shares[$index],
                'line_total' => Money::sub($lineSubtotal, $shares[$index]),
            ];
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => Money::sub($subtotal, $discount),
            'lines' => $computed,
        ];
    }

    /**
     * Value of returning $quantity units of a purchase line, at its net (post-discount) cost.
     * Returning everything that remains takes exactly the remaining value, so cents never drift.
     */
    public function returnValue(PurchaseItem $item, int $quantity): string
    {
        if ($quantity < 1 || $quantity > $item->returnableQuantity()) {
            throw new InvalidArgumentException('Invalid return quantity.');
        }

        if ($quantity === $item->returnableQuantity()) {
            return Money::sub(Money::of($item->line_total), Money::of($item->returned_amount));
        }

        return Money::proportion(Money::of($item->line_total), $quantity, $item->quantity);
    }

    /**
     * Net unit cost of a line, for stock movement valuation.
     */
    public function netUnitCost(string $lineTotal, int $quantity): string
    {
        return Money::proportion($lineTotal, 1, $quantity);
    }
}
