<?php

namespace App\Domain\MobileService;

use App\Enums\PaymentStatus;
use App\Models\ServiceInvoice;
use App\Support\Money;

/**
 * Recomputes a service invoice's paid / due / status caches from its payment allocations.
 * The invoice document itself (lines, prices, totals) is never changed.
 */
class ServiceInvoiceSettlement
{
    public function refresh(ServiceInvoice $invoice): ServiceInvoice
    {
        $paid = Money::of((string) $invoice->allocations()->sum('amount'));
        $due = Money::max('0.00', Money::sub(Money::of($invoice->total), $paid));

        $invoice->forceFill([
            'paid_amount' => $paid,
            'due_amount' => $due,
            'payment_status' => PaymentStatus::resolve($paid, $due),
        ])->save();

        return $invoice;
    }
}
