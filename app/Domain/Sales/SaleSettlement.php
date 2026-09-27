<?php

namespace App\Domain\Sales;

use App\Enums\PaymentStatus;
use App\Models\Sale;
use App\Support\Money;

/**
 * Recomputes a sale's paid / due / status caches from its payment allocations.
 */
class SaleSettlement
{
    public function refresh(Sale $sale): Sale
    {
        $paid = Money::of((string) $sale->allocations()->sum('amount'));
        $due = Money::max('0.00', Money::sub(Money::of($sale->total), $paid));

        $sale->forceFill([
            'paid_amount' => $paid,
            'due_amount' => $due,
            'payment_status' => PaymentStatus::resolve($paid, $due),
        ])->save();

        return $sale;
    }
}
