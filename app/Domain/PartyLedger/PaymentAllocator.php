<?php

namespace App\Domain\PartyLedger;

use App\Enums\OpeningBalanceType;
use App\Enums\PaymentDirection;
use App\Enums\PaymentPurpose;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Purchase;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Reconciles payments with the documents they settle.
 *
 * An OUT payment that is not (fully) allocated is money the supplier holds on account —
 * an advance. So is a supplier's receivable opening balance (money they held before the party
 * was added); purchases consume it through allocations that have no payment.
 * Callers must hold the party lock (PartyLedgerService::lock) so pools stay consistent.
 */
class PaymentAllocator
{
    /**
     * Only money paid to a supplier can be held as an advance (not, e.g., customer refunds).
     */
    private const ADVANCE_PURPOSES = [PaymentPurpose::PurchasePayment, PaymentPurpose::SupplierAdvance];

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
     * It is the unconsumed opening advance plus the unallocated part of OUT payments, capped by
     * the ledger balance: money held on account that has already been absorbed by other payables
     * is not an advance.
     *
     * $ledgerOffset adds back a payable already posted for the document being settled (an existing
     * purchase's outstanding due), since that payable is what the advance is meant to cover.
     */
    public function availableAdvance(Party $party, string $ledgerOffset = '0.00'): string
    {
        $unallocated = Payment::query()
            ->where('party_id', $party->id)
            ->where('direction', PaymentDirection::Out)
            ->whereIn('purpose', self::ADVANCE_PURPOSES)
            ->selectRaw('COALESCE(SUM(amount - allocated_amount), 0) as unallocated')
            ->value('unallocated');

        $pool = Money::add($this->openingAdvanceRemaining($party), Money::of((string) $unallocated));
        $cap = Money::add(Money::of($party->balance), $ledgerOffset);

        return Money::max('0.00', Money::min($pool, $cap));
    }

    /**
     * Apply the opening advance, then unallocated OUT payments (oldest first), to a document, up to $limit.
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

        $fromOpening = Money::min($remaining, $this->openingAdvanceRemaining($party));

        if (Money::isPositive($fromOpening)) {
            $this->allocateFromOpening($allocatable, $fromOpening);
            $applied = $fromOpening;
            $remaining = Money::sub($remaining, $fromOpening);
        }

        foreach (Money::isPositive($remaining) ? $this->openPayments($party) : [] as $payment) {
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
     * The part of a supplier's receivable opening balance not yet applied to a purchase.
     */
    public function openingAdvanceRemaining(Party $party): string
    {
        if (! $party->isSupplier() || $party->opening_balance_type !== OpeningBalanceType::Receivable) {
            return '0.00';
        }

        $used = PaymentAllocation::query()
            ->whereNull('payment_id')
            ->where('allocatable_type', (new Purchase)->getMorphClass())
            ->whereIn('allocatable_id', Purchase::query()->select('id')->where('party_id', $party->id))
            ->sum('amount');

        return Money::max('0.00', Money::sub(Money::of($party->opening_balance), Money::of((string) $used)));
    }

    private function allocateFromOpening(Model $allocatable, string $amount): PaymentAllocation
    {
        return PaymentAllocation::create([
            'payment_id' => null,
            'allocatable_type' => $allocatable->getMorphClass(),
            'allocatable_id' => $allocatable->getKey(),
            'amount' => $amount,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * @return Collection<int, Payment>
     */
    private function openPayments(Party $party): Collection
    {
        return Payment::query()
            ->where('party_id', $party->id)
            ->where('direction', PaymentDirection::Out)
            ->whereIn('purpose', self::ADVANCE_PURPOSES)
            ->whereColumn('allocated_amount', '<', 'amount')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
