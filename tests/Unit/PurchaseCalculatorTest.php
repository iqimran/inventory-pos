<?php

namespace Tests\Unit;

use App\Domain\Purchasing\PurchaseCalculator;
use App\Models\PurchaseItem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PurchaseCalculatorTest extends TestCase
{
    public function test_totals_spread_discount_proportionally_and_exactly()
    {
        $totals = (new PurchaseCalculator)->totals([
            ['quantity' => 1, 'unit_cost' => '100.00'],
            ['quantity' => 1, 'unit_cost' => '100.00'],
            ['quantity' => 1, 'unit_cost' => '100.00'],
        ], '10.00');

        $this->assertSame('300.00', $totals['subtotal']);
        $this->assertSame('290.00', $totals['total']);
        // Largest remainder: shares sum exactly to the discount.
        $this->assertSame(['3.34', '3.33', '3.33'], array_column($totals['lines'], 'discount_share'));
        $this->assertSame('290.00', bcadd(bcadd($totals['lines'][0]['line_total'], $totals['lines'][1]['line_total'], 2), $totals['lines'][2]['line_total'], 2));
    }

    public function test_small_discount_never_produces_a_negative_share()
    {
        // Regression: naive per-line rounding gave 0.01 + 0.01 + 0.01 + (-0.01).
        $totals = (new PurchaseCalculator)->totals(array_fill(0, 4, ['quantity' => 1, 'unit_cost' => '1.00']), '0.02');

        $shares = array_column($totals['lines'], 'discount_share');
        $this->assertSame(['0.01', '0.01', '0.00', '0.00'], $shares);
        $this->assertSame('3.98', $totals['total']);
    }

    public function test_discount_cannot_exceed_subtotal()
    {
        $this->expectException(InvalidArgumentException::class);

        (new PurchaseCalculator)->totals([['quantity' => 1, 'unit_cost' => '10.00']], '10.01');
    }

    public function test_partial_returns_never_drift_from_the_line_total()
    {
        $calculator = new PurchaseCalculator;
        $item = new PurchaseItem(['quantity' => 3, 'line_total' => '100.00', 'returned_quantity' => 0, 'returned_amount' => '0.00']);

        $first = $calculator->returnValue($item, 1);
        $item->returned_quantity = 1;
        $item->returned_amount = $first;
        $second = $calculator->returnValue($item, 1);
        $item->returned_quantity = 2;
        $item->returned_amount = bcadd($first, $second, 2);
        $last = $calculator->returnValue($item, 1);

        $this->assertSame(['33.33', '33.33', '33.34'], [$first, $second, $last]);
    }

    public function test_return_quantity_is_bounded()
    {
        $this->expectException(InvalidArgumentException::class);

        (new PurchaseCalculator)->returnValue(new PurchaseItem(['quantity' => 2, 'line_total' => '10.00', 'returned_quantity' => 2, 'returned_amount' => '10.00']), 1);
    }
}
