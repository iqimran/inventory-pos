<?php

namespace App\Actions\Purchasing;

use App\Domain\Inventory\Data\StockMovementData;
use App\Domain\Inventory\StockService;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\Purchasing\PurchaseCalculator;
use App\Domain\Purchasing\PurchaseSettlement;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\StockMovementType;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use App\Support\TransactionDate;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns goods from an original purchase to the supplier in one transaction: return document,
 * stock-out movements, supplier ledger debit (reducing the payable), and an optional cash refund.
 */
class CreatePurchaseReturn
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly StockService $stock,
        private readonly PurchaseCalculator $calculator,
        private readonly PurchaseSettlement $settlement,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{return_date: string, reason: string, items: list<array{purchase_item_id: int, quantity: int}>,
     *               refund_amount?: ?string, refund_method?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(Purchase $purchase, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($purchase, $data): PurchaseReturn {
            $party = $this->ledger->lock($purchase->party_id);
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $occurredAt = TransactionDate::at($data['return_date']);

            if ($occurredAt->startOfDay()->lt($purchase->purchase_date)) {
                throw ValidationException::withMessages(['return_date' => 'The return date cannot be before the purchase date.']);
            }

            // Lock the lines so concurrent returns cannot exceed the purchased quantity.
            $items = PurchaseItem::where('purchase_id', $purchase->id)
                ->whereKey(array_column($data['items'], 'purchase_item_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = [];
            $total = '0.00';

            foreach ($data['items'] as $index => $line) {
                /** @var PurchaseItem|null $item */
                $item = $items->get($line['purchase_item_id']);

                if (! $item) {
                    throw ValidationException::withMessages(["items.{$index}.purchase_item_id" => 'This item is not part of the purchase.']);
                }

                if ($line['quantity'] > $item->returnableQuantity()) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Only {$item->returnableQuantity()} unit(s) of this item can still be returned.",
                    ]);
                }

                $value = $this->calculator->returnValue($item, $line['quantity']);
                $total = Money::add($total, $value);
                $lines[] = [$item, $line['quantity'], $value];
            }

            $return = PurchaseReturn::create([
                'return_no' => $this->numbers->next('PRT', $occurredAt),
                'purchase_id' => $purchase->id,
                'party_id' => $party->id,
                'return_date' => $data['return_date'],
                'total' => $total,
                'reason' => $data['reason'],
            ]);

            $movements = [];

            foreach ($lines as [$item, $quantity, $value]) {
                $return->items()->create([
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $quantity,
                    'unit_cost' => $this->calculator->netUnitCost($value, $quantity),
                    'line_total' => $value,
                ]);

                $item->forceFill([
                    'returned_quantity' => $item->returned_quantity + $quantity,
                    'returned_amount' => Money::add(Money::of($item->returned_amount), $value),
                ])->save();

                $movements[] = new StockMovementData(
                    productId: $item->product_id,
                    type: StockMovementType::PurchaseReturnOut,
                    quantity: $quantity,
                    unitCost: $this->calculator->netUnitCost($value, $quantity),
                    reference: $return,
                    notes: "{$return->return_no} ({$purchase->purchase_no})",
                    occurredAt: $occurredAt,
                );
            }

            // Fails with a validation error if the goods are no longer in stock.
            $this->stock->recordMany($movements);

            $purchase->returned_amount = Money::add(Money::of($purchase->returned_amount), $total);
            $this->settlement->refresh($purchase);

            $this->ledger->debit($party, LedgerEntryType::PurchaseReturn, $total, $return, "Return {$return->return_no} for {$purchase->purchase_no}", $occurredAt);

            $this->recordRefund($return, $party, $data, $occurredAt);

            return $return;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordRefund(PurchaseReturn $return, Party $party, array $data, DateTimeInterface $occurredAt): void
    {
        $refund = Money::of($data['refund_amount'] ?? '0');

        if (! Money::isPositive($refund)) {
            return;
        }

        // Cash back is only due while the supplier owes the shop (after the return is posted).
        $refundable = Money::max('0.00', Money::min(Money::of($return->total), Money::of($party->balance)));

        if (Money::cmp($refund, $refundable) > 0) {
            throw ValidationException::withMessages([
                'refund_amount' => "The refund cannot exceed {$refundable} (the amount the supplier owes after this return).",
            ]);
        }

        $payment = Payment::create([
            'payment_no' => $this->numbers->next('PAY', $occurredAt),
            'party_id' => $party->id,
            'direction' => PaymentPurpose::SupplierRefund->direction(),
            'purpose' => PaymentPurpose::SupplierRefund,
            'method' => PaymentMethod::from($data['refund_method'] ?? PaymentMethod::Cash->value),
            'amount' => $refund,
            'paid_at' => $occurredAt,
            'source_type' => $return->getMorphClass(),
            'source_id' => $return->id,
        ]);

        $return->forceFill(['refund_amount' => $refund])->save();

        $this->ledger->credit($party, LedgerEntryType::SupplierRefund, $refund, $payment, "Refund {$payment->payment_no} for {$return->return_no}", $occurredAt);
    }
}
