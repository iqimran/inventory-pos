<?php

namespace App\Domain\Purchasing;

use App\Enums\PaymentStatus;
use App\Models\Purchase;
use App\Support\Money;

/**
 * Recomputes a purchase's paid / due / status caches from its allocations and returns.
 */
class PurchaseSettlement
{
    public function refresh(Purchase $purchase): Purchase
    {
        $paid = Money::of((string) $purchase->allocations()->sum('amount'));
        $net = Money::sub(Money::of($purchase->total), Money::of($purchase->returned_amount));
        $due = Money::max('0.00', Money::sub($net, $paid));

        $purchase->forceFill([
            'paid_amount' => $paid,
            'due_amount' => $due,
            'payment_status' => PaymentStatus::resolve($paid, $due),
        ])->save();

        return $purchase;
    }
}
