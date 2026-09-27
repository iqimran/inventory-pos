<?php

namespace App\Actions\Sales;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Domain\Sales\SaleSettlement;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Collects money a customer owes: for one sale, or "on account" settling the oldest dues first.
 * Any part not matched to a sale settles other receivables such as the opening balance.
 */
class CollectSalePayment
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PaymentAllocator $allocator,
        private readonly SaleSettlement $settlement,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Party $party, string $amount, PaymentMethod $method, string $date, ?Sale $sale = null, ?string $referenceNo = null, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($party, $amount, $method, $date, $sale, $referenceNo, $notes): Payment {
            $party = $this->ledger->lock($party);
            $amount = Money::of($amount);
            $paidAt = TransactionDate::at($date);

            if ($sale) {
                $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

                if ($sale->party_id !== $party->id) {
                    throw ValidationException::withMessages(['sale_id' => 'The sale belongs to another customer.']);
                }

                if (Money::cmp($amount, Money::of($sale->due_amount)) > 0) {
                    throw ValidationException::withMessages(['amount' => "The amount exceeds the sale due of {$sale->due_amount}."]);
                }
            } else {
                $receivable = Money::max('0.00', Money::of($party->balance));

                if (Money::cmp($amount, $receivable) > 0) {
                    throw ValidationException::withMessages(['amount' => "The amount exceeds the customer's receivable balance of {$receivable}."]);
                }
            }

            $payment = Payment::create([
                'payment_no' => $this->numbers->next('PAY', $paidAt),
                'party_id' => $party->id,
                'direction' => PaymentPurpose::SalePayment->direction(),
                'purpose' => PaymentPurpose::SalePayment,
                'method' => $method,
                'amount' => $amount,
                'reference_no' => $referenceNo,
                'paid_at' => $paidAt,
                'notes' => $notes,
            ]);

            $targets = $sale
                ? collect([$sale])
                : Sale::query()
                    ->where('party_id', $party->id)
                    ->where('due_amount', '>', 0)
                    ->orderBy('sold_at')
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

            $description = $sale ? "Payment {$payment->payment_no} for {$sale->invoice_no}" : "Payment {$payment->payment_no} on account";
            $this->ledger->credit($party, LedgerEntryType::CustomerPayment, $amount, $payment, $description, $paidAt);

            return $payment;
        }, 3);
    }
}
