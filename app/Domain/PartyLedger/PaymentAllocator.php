<?php

namespace App\Domain\PartyLedger;

use App\Enums\PaymentDirection;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Reconciles payments with the documents they settle.
 *
 * An OUT payment that is not (fully) allocated is money the supplier holds on account —
 * an advance. Callers must hold the party lock (PartyLedgerService::lock) so pools stay consistent.
 */
class PaymentAllocator
{
    public function allocate(Payment $payment, Model $allocatable, string $amount): PaymentAllocation
    {
        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => $allocatable->getMorphClass(),
            'allocatable_id' => $allocatable->getKey(),
            'amount' => $amount,
            'created_by' => Auth::id(),
        ]);

        $payment->allocated_amount = Money::add(Money::of($payment->allocated_amount), $amount);
        $payment->save();

        return $allocation;
    }

    /**
     * Advance the supplier currently holds for the shop.
     *
     * It is the unallocated part of OUT payments, capped by the ledger balance: money paid on
     * account that has already been absorbed by an opening balance or other payables is not an advance.
     *
     * $ledgerOffset adds back a payable already posted for the document being settled (an existing
     * purchase's outstanding due), since that payable is what the advance is meant to cover.
     */
    public function availableAdvance(Party $party, string $ledgerOffset = '0.00'): string
    {
        $unallocated = Payment::query()
            ->where('party_id', $party->id)
            ->where('direction', PaymentDirection::Out)
            ->selectRaw('COALESCE(SUM(amount - allocated_amount), 0) as unallocated')
            ->value('unallocated');

        $cap = Money::add(Money::of($party->balance), $ledgerOffset);

        return Money::max('0.00', Money::min(Money::of((string) $unallocated), $cap));
    }

    /**
     * Apply unallocated OUT payments (oldest first) to a document, up to $limit.
     *
     * @return string amount applied
     */
    public function applyAdvance(Party $party, Model $allocatable, string $limit, string $ledgerOffset = '0.00'): string
    {
        $remaining = Money::min($limit, $this->availableAdvance($party, $ledgerOffset));
        $applied = '0.00';

        if (! Money::isPositive($remaining)) {
            return $applied;
        }

        foreach ($this->openPayments($party) as $payment) {
            $take = Money::min($remaining, $payment->unallocatedAmount());

            if (! Money::isPositive($take)) {
                continue;
            }

            $this->allocate($payment, $allocatable, $take);
            $applied = Money::add($applied, $take);
            $remaining = Money::sub($remaining, $take);

            if (! Money::isPositive($remaining)) {
                break;
            }
        }

        return $applied;
    }

    /**
     * @return Collection<int, Payment>
     */
    private function openPayments(Party $party): Collection
    {
        return Payment::query()
            ->where('party_id', $party->id)
            ->where('direction', PaymentDirection::Out)
            ->whereColumn('allocated_amount', '<', 'amount')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
