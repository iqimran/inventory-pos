<?php

namespace App\Actions\Purchasing;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Models\Party;
use App\Models\Payment;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;

/**
 * Money paid to a supplier before any purchase exists. It stays unallocated until a later
 * purchase consumes it (see PaymentAllocator::applyAdvance).
 */
class RecordSupplierAdvance
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    public function handle(Party $party, string $amount, PaymentMethod $method, string $date, ?string $referenceNo = null, ?string $notes = null): Payment
    {
        return DB::transaction(function () use ($party, $amount, $method, $date, $referenceNo, $notes): Payment {
            $party = $this->ledger->lock($party);
            $paidAt = TransactionDate::at($date);

            $payment = Payment::create([
                'payment_no' => $this->numbers->next('PAY', $paidAt),
                'party_id' => $party->id,
                'direction' => PaymentPurpose::SupplierAdvance->direction(),
                'purpose' => PaymentPurpose::SupplierAdvance,
                'method' => $method,
                'amount' => Money::of($amount),
                'reference_no' => $referenceNo,
                'paid_at' => $paidAt,
                'notes' => $notes,
            ]);

            $this->ledger->debit($party, LedgerEntryType::SupplierAdvance, $payment->amount, $payment, "Advance {$payment->payment_no}", $paidAt);

            return $payment;
        }, 3);
    }
}
