<?php

namespace App\Actions\Purchasing;

use App\Domain\Inventory\Data\StockMovementData;
use App\Domain\Inventory\StockService;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Domain\Purchasing\PurchaseCalculator;
use App\Domain\Purchasing\PurchaseSettlement;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Models\Payment;
use App\Models\Purchase;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a purchase in one transaction: purchase + items, stock-in movements, supplier ledger
 * payable, optional advance consumption, and the payment made at purchase time.
 */
class CreatePurchase
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PaymentAllocator $allocator,
        private readonly StockService $stock,
        private readonly PurchaseCalculator $calculator,
        private readonly PurchaseSettlement $settlement,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{party_id: int, purchase_date: string, supplier_invoice_no?: ?string, discount?: ?string, notes?: ?string,
     *               items: list<array{product_id: int, quantity: int, unit_cost: string}>,
     *               paid_amount?: ?string, payment_method?: ?string, payment_reference?: ?string, apply_advance?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Purchase
    {
        return DB::transaction(function () use ($data): Purchase {
            // Lock order: party first, then stock (inside StockService).
            $party = $this->ledger->lock($data['party_id']);
            $occurredAt = TransactionDate::at($data['purchase_date']);
            $totals = $this->calculator->totals($data['items'], $data['discount'] ?? '0');
            $paidNow = Money::of($data['paid_amount'] ?? '0');

            $purchase = Purchase::create([
                'purchase_no' => $this->numbers->next('PUR', $occurredAt),
                'party_id' => $party->id,
                'purchase_date' => $data['purchase_date'],
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'total' => $totals['total'],
                'due_amount' => $totals['total'],
                'payment_status' => PaymentStatus::Due,
                'notes' => $data['notes'] ?? null,
            ]);

            $movements = [];

            foreach ($data['items'] as $index => $line) {
                $item = $purchase->items()->create([
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => Money::of($line['unit_cost']),
                    ...$totals['lines'][$index],
                ]);

                $movements[] = new StockMovementData(
                    productId: $item->product_id,
                    type: StockMovementType::PurchaseIn,
                    quantity: $item->quantity,
                    unitCost: $this->calculator->netUnitCost($item->line_total, $item->quantity),
                    reference: $purchase,
                    notes: $purchase->purchase_no,
                    occurredAt: $occurredAt,
                );
            }

            $this->stock->recordMany($movements);

            // Consume an existing advance before the payable is posted (the advance is part of the balance now).
            $advanceApplied = ($data['apply_advance'] ?? false)
                ? $this->allocator->applyAdvance($party, $purchase, $totals['total'])
                : '0.00';

            if (Money::cmp($paidNow, Money::sub($totals['total'], $advanceApplied)) > 0) {
                throw ValidationException::withMessages([
                    'paid_amount' => 'The paid amount exceeds the amount due'.(Money::isPositive($advanceApplied) ? " after applying {$advanceApplied} of advance." : '.'),
                ]);
            }

            if (Money::isPositive($totals['total'])) {
                $this->ledger->credit($party, LedgerEntryType::Purchase, $totals['total'], $purchase, "Purchase {$purchase->purchase_no}", $occurredAt);
            }

            if (Money::isPositive($paidNow)) {
                $payment = Payment::create([
                    'payment_no' => $this->numbers->next('PAY', $occurredAt),
                    'party_id' => $party->id,
                    'direction' => PaymentPurpose::PurchasePayment->direction(),
                    'purpose' => PaymentPurpose::PurchasePayment,
                    'method' => PaymentMethod::from($data['payment_method'] ?? PaymentMethod::Cash->value),
                    'amount' => $paidNow,
                    'reference_no' => $data['payment_reference'] ?? null,
                    'paid_at' => $occurredAt,
                    'source_type' => $purchase->getMorphClass(),
                    'source_id' => $purchase->id,
                ]);

                $this->allocator->allocate($payment, $purchase, $paidNow);
                $this->ledger->debit($party, LedgerEntryType::PurchasePayment, $paidNow, $payment, "Payment {$payment->payment_no} for {$purchase->purchase_no}", $occurredAt);
            }

            return $this->settlement->refresh($purchase);
        }, 3);
    }
}
