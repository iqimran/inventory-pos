<?php

namespace App\Domain\Sales;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure money calculations for a sale: line discounts first, then the invoice discount is
 * spread over the discounted lines (largest remainder, exact to the cent).
 */
class SaleCalculator
{
    /**
     * @param  list<array{quantity: int, unit_price: string, discount?: ?string}>  $lines
     * @return array{subtotal: string, items_discount: string, discount: string, total: string,
     *               lines: list<array{line_subtotal: string, line_discount: string, discount_share: string, line_total: string}>}
     */
    public function totals(array $lines, string $discount): array
    {
        $discount = Money::of($discount);
        $computed = [];
        $afterLineDiscounts = [];

        foreach ($lines as $index => $line) {
            $lineSubtotal = Money::mul(Money::of($line['unit_price']), $line['quantity']);
            $lineDiscount = Money::of($line['discount'] ?? '0');

            if (Money::isNegative($lineDiscount) || Money::cmp($lineDiscount, $lineSubtotal) > 0) {
                throw new InvalidArgumentException("Line {$index}: discount must be between zero and the line amount.");
            }

            $computed[] = ['line_subtotal' => $lineSubtotal, 'line_discount' => $lineDiscount];
            $afterLineDiscounts[] = Money::sub($lineSubtotal, $lineDiscount);
        }

        $net = Money::add(...$afterLineDiscounts);

        if (Money::isNegative($discount) || Money::cmp($discount, $net) > 0) {
            throw new InvalidArgumentException('Invoice discount must be between zero and the discounted subtotal.');
        }

        $shares = Money::allocate($discount, $afterLineDiscounts);

        foreach ($computed as $index => $line) {
            $computed[$index]['discount_share'] = $shares[$index];
            $computed[$index]['line_total'] = Money::sub($afterLineDiscounts[$index], $shares[$index]);
        }

        return [
            'subtotal' => Money::add(...array_column($computed, 'line_subtotal')),
            'items_discount' => Money::add(...array_column($computed, 'line_discount')),
            'discount' => $discount,
            'total' => Money::sub($net, $discount),
            'lines' => $computed,
        ];
    }
}
