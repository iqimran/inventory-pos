<?php

namespace App\Actions\Purchasing;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Domain\Purchasing\PurchaseSettlement;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays a supplier against outstanding purchases: either one purchase, or "on account"
 * settling the oldest due purchases first. Any part not matched to a purchase settles
 * other payables such as the opening balance.
 */
class RecordPurchasePayment
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PaymentAllocator $allocator,
        private readonly PurchaseSettlement $settlement,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Party $party, string $amount, PaymentMethod $method, string $date, ?Purchase $purchase = null, ?string $referenceNo = null, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($party, $amount, $method, $date, $purchase, $referenceNo, $notes): Payment {
            $party = $this->ledger->lock($party);
            $amount = Money::of($amount);
            $paidAt = TransactionDate::at($date);

            if ($purchase) {
                $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

                if ($purchase->party_id !== $party->id) {
                    throw ValidationException::withMessages(['purchase_id' => 'The purchase belongs to another supplier.']);
                }

                if (Money::cmp($amount, Money::of($purchase->due_amount)) > 0) {
                    throw ValidationException::withMessages(['amount' => "The amount exceeds the purchase due of {$purchase->due_amount}."]);
                }
            } else {
                $payable = Money::max('0.00', Money::negate(Money::of($party->balance)));

                if (Money::cmp($amount, $payable) > 0) {
                    throw ValidationException::withMessages([
                        'amount' => "The amount exceeds the payable balance of {$payable}. Record the excess as a supplier advance.",
                    ]);
                }
            }

            $payment = Payment::create([
                'payment_no' => $this->numbers->next('PAY', $paidAt),
                'party_id' => $party->id,
                'direction' => PaymentPurpose::PurchasePayment->direction(),
                'purpose' => PaymentPurpose::PurchasePayment,
                'method' => $method,
                'amount' => $amount,
                'reference_no' => $referenceNo,
                'paid_at' => $paidAt,
                'notes' => $notes,
            ]);

            $targets = $purchase
                ? collect([$purchase])
                : Purchase::query()
                    ->where('party_id', $party->id)
                    ->where('due_amount', '>', 0)
                    ->orderBy('purchase_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            $remaining = $amount;

            foreach ($targets as $target) {
                $take = Money::min($remaining, Money::of($target->due_amount));

                if (Money::isPositive($take)) {
                    $this->allocator->allocate($payment, $target, $take);
                    $this->settlement->refresh($target);
                    $remaining = Money::sub($remaining, $take);
                }

                if (! Money::isPositive($remaining)) {
                    break;
                }
            }

            $description = $purchase ? "Payment {$payment->payment_no} for {$purchase->purchase_no}" : "Payment {$payment->payment_no} on account";
            $this->ledger->debit($party, LedgerEntryType::PurchasePayment, $amount, $payment, $description, $paidAt);

            return $payment;
        }, 3);
    }
}
