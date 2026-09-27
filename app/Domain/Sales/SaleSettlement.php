<?php

namespace App\Domain\Sales;

use App\Enums\PaymentStatus;
use App\Models\Sale;
use App\Support\Money;

/**
 * Recomputes a sale's paid / due / status caches from its payment allocations and returns.
 * The sale document itself (lines, prices, totals) is never changed.
 */
class SaleSettlement
{
    public function refresh(Sale $sale): Sale
    {
        $paid = Money::of((string) $sale->allocations()->sum('amount'));
        $returned = Money::of((string) $sale->returns()->sum('subtotal'));
        $due = Money::max('0.00', Money::sub(Money::sub(Money::of($sale->total), $returned), $paid));

        $sale->forceFill([
            'paid_amount' => $paid,
            'returned_amount' => $returned,
            'due_amount' => $due,
            'payment_status' => PaymentStatus::resolve($paid, $due),
        ])->save();

        return $sale;
    }
}
