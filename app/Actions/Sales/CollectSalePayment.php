<?php

namespace App\Actions\Sales;

use App\Domain\MobileService\ServiceInvoiceSettlement;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Domain\Sales\SaleSettlement;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use App\Support\TransactionDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Collects money a customer owes: for one sale or service invoice, or "on account" settling the
 * oldest dues first (sales and service invoices together). Any part not matched to a document
 * settles other receivables such as the opening balance.
 */
class CollectSalePayment
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PaymentAllocator $allocator,
        private readonly SaleSettlement $settlement,
        private readonly ServiceInvoiceSettlement $serviceSettlement,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Party $party, string $amount, PaymentMethod $method, string $date, ?Sale $sale = null, ?string $referenceNo = null, ?string $notes = null, ?ServiceInvoice $serviceInvoice = null): Payment
    {
        return DB::transaction(function () use ($party, $amount, $method, $date, $sale, $referenceNo, $notes, $serviceInvoice): Payment {
            $party = $this->ledger->lock($party);
            $amount = Money::of($amount);
            $paidAt = TransactionDate::at($date);

            if ($sale && $serviceInvoice) {
                throw ValidationException::withMessages(['sale_id' => 'Apply the payment to one document at a time.']);
            }

            $document = null;

            if ($sale) {
                $document = $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

                if ($sale->party_id !== $party->id) {
                    throw ValidationException::withMessages(['sale_id' => 'The sale belongs to another customer.']);
                }

                if (Money::cmp($amount, Money::of($sale->due_amount)) > 0) {
                    throw ValidationException::withMessages(['amount' => "The amount exceeds the sale due of {$sale->due_amount}."]);
                }
            } elseif ($serviceInvoice) {
                $document = $serviceInvoice = ServiceInvoice::whereKey($serviceInvoice->id)->lockForUpdate()->firstOrFail();

                if ($serviceInvoice->party_id !== $party->id) {
                    throw ValidationException::withMessages(['service_invoice_id' => 'The service invoice belongs to another customer.']);
                }

                if (Money::cmp($amount, Money::of($serviceInvoice->due_amount)) > 0) {
                    throw ValidationException::withMessages(['amount' => "The amount exceeds the service invoice due of {$serviceInvoice->due_amount}."]);
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

            $targets = $document ? collect([$document]) : $this->openDocuments($party);
            $remaining = $amount;

            foreach ($targets as $target) {
                $take = Money::min($remaining, Money::of($target->due_amount));

                if (Money::isPositive($take)) {
                    $this->allocator->allocate($payment, $target, $take);
                    $target instanceof Sale ? $this->settlement->refresh($target) : $this->serviceSettlement->refresh($target);
                    $remaining = Money::sub($remaining, $take);
                }

                if (! Money::isPositive($remaining)) {
                    break;
                }
            }

            $description = $document ? "Payment {$payment->payment_no} for {$document->invoice_no}" : "Payment {$payment->payment_no} on account";
            $this->ledger->credit($party, LedgerEntryType::CustomerPayment, $amount, $payment, $description, $paidAt);

            return $payment;
        }, 3);
    }

    /**
     * The customer's sales and service invoices with a due, oldest first (locked).
     *
     * @return Collection<int, Sale|ServiceInvoice>
     */
    private function openDocuments(Party $party): Collection
    {
        $sales = Sale::query()
            ->where('party_id', $party->id)
            ->where('due_amount', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $serviceInvoices = ServiceInvoice::query()
            ->where('party_id', $party->id)
            ->where('due_amount', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $sales->toBase()->merge($serviceInvoices)
            ->sortBy(fn (Model $document) => [
                ($document instanceof Sale ? $document->sold_at : $document->invoiced_at)->getTimestamp(),
                $document instanceof Sale ? 0 : 1,
                $document->getKey(),
            ])
            ->values();
    }
}
