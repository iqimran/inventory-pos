<?php

namespace App\Actions\Purchasing;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Domain\Purchasing\PurchaseSettlement;
use App\Models\Purchase;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reconciles a supplier's unapplied advance against a purchase that is still due.
 * No ledger entry: the advance was already posted when it was paid.
 */
class ApplyAdvanceToPurchase
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PaymentAllocator $allocator,
        private readonly PurchaseSettlement $settlement,
    ) {}

    /**
     * @return string amount applied
     *
     * @throws ValidationException
     */
    public function handle(Purchase $purchase): string
    {
        return DB::transaction(function () use ($purchase): string {
            $party = $this->ledger->lock($purchase->party_id);
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            // A purchase's payable is already in the balance, so the pool available to it is the
            // unallocated payments plus this purchase's own outstanding due.
            $applied = $this->allocator->applyAdvance($party, $purchase, Money::of($purchase->due_amount), Money::of($purchase->due_amount));

            if (! Money::isPositive($applied)) {
                throw ValidationException::withMessages(['advance' => 'This supplier has no unapplied advance to use.']);
            }

            $this->settlement->refresh($purchase);

            return $applied;
        }, 3);
    }
}
