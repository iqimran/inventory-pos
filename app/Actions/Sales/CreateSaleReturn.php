<?php

namespace App\Actions\Sales;

use App\Domain\Inventory\Data\StockMovementData;
use App\Domain\Inventory\StockService;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\Sales\SaleSettlement;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accepts goods back from a customer against an original sale, in one transaction:
 * return document, stock-in movements (at the original cost snapshot), customer ledger
 * credit, and an optional cash refund. The original sale's lines and totals are never
 * modified; only its derived settlement caches are recomputed.
 *
 * Financial effect of a return worth R on a sale with outstanding due D:
 * - min(R, D) reduces what is still owed on the sale (adjustment),
 * - the rest was already paid: it is refunded in cash and/or left as store credit.
 * Walk-in sales have no account to hold credit, so their returns are refunded in full.
 */
class CreateSaleReturn
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly StockService $stock,
        private readonly SaleSettlement $settlement,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{reason: string, items: list<array{sale_item_id: int, quantity: int}>,
     *               refund_amount?: ?string, refund_method?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(Sale $sale, array $data): SaleReturn
    {
        return DB::transaction(function () use ($sale, $data): SaleReturn {
            // Lock order: party, then the sale (serialises returns of this sale), then stock.
            $party = $sale->party_id ? $this->ledger->lock($sale->party_id) : null;
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $returnedAt = now();

            $lines = $this->resolveLines($sale, $data['items']);
            $value = Money::add(...array_column($lines, 'amount'));

            $adjustment = Money::min($value, Money::of($sale->due_amount));
            $alreadyPaidPart = Money::sub($value, $adjustment);
            $refund = $this->resolveRefund($party, $value, $alreadyPaidPart, $data);
            $method = Money::isPositive($refund) ? PaymentMethod::from($data['refund_method'] ?? PaymentMethod::Cash->value) : null;

            $return = SaleReturn::create([
                'return_no' => $this->numbers->next('SRT', $returnedAt),
                'sale_id' => $sale->id,
                'party_id' => $party?->id,
                'status' => SaleStatus::Completed,
                'returned_at' => $returnedAt,
                'subtotal' => $value,
                'adjustment_amount' => $adjustment,
                'refund_amount' => $refund,
                'credit_amount' => Money::sub($alreadyPaidPart, $refund),
                'refund_method' => $method,
                'reason' => $data['reason'],
            ]);

            foreach ($lines as $line) {
                $return->items()->create($line);
            }

            $this->stock->recordMany(array_map(fn (array $line) => new StockMovementData(
                productId: $line['product_id'],
                type: StockMovementType::SaleReturnIn,
                quantity: $line['quantity'],
                unitCost: $line['unit_cost'],
                reference: $return,
                notes: "{$return->return_no} ({$sale->invoice_no})",
                occurredAt: $returnedAt,
            ), $lines));

            if ($party) {
                $this->ledger->credit($party, LedgerEntryType::SaleReturn, $value, $return, "Return {$return->return_no} for {$sale->invoice_no}", $returnedAt);
            }

            if (Money::isPositive($refund)) {
                $this->recordRefund($return, $party, $refund, $method);
            }

            $this->settlement->refresh($sale);

            return $return;
        }, 3);
    }

    /**
     * Validate requested lines against what is still eligible and value them.
     *
     * @param  list<array{sale_item_id: int, quantity: int}>  $items
     * @return list<array{sale_item_id: int, product_id: int, quantity: int, unit_price: string, amount: string, unit_cost: string, cost_total: string}>
     *
     * @throws ValidationException
     */
    private function resolveLines(Sale $sale, array $items): array
    {
        $saleItems = SaleItem::query()
            ->where('sale_id', $sale->id)
            ->whereKey(array_column($items, 'sale_item_id'))
            ->withSum('returnItems as returned_quantity', 'quantity')
            ->withSum('returnItems as returned_value', 'amount')
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($items as $index => $requested) {
            /** @var SaleItem|null $item */
            $item = $saleItems->get($requested['sale_item_id']);

            if (! $item) {
                throw ValidationException::withMessages(["items.{$index}.sale_item_id" => 'This item is not part of the sale.']);
            }

            $alreadyReturned = (int) $item->returned_quantity;
            $eligible = $item->quantity - $alreadyReturned;
            $quantity = (int) $requested['quantity'];

            if ($quantity > $eligible) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => $eligible === 0
                        ? 'This item has already been fully returned.'
                        : "Only {$eligible} unit(s) of this item can still be returned.",
                ]);
            }

            // Value at what the customer actually paid (after line and invoice discounts). Returning
            // everything that remains takes the exact remaining value, so cents never drift.
            $amount = $quantity === $eligible
                ? Money::sub(Money::of($item->line_total), Money::of((string) ($item->returned_value ?? '0')))
                : Money::proportion(Money::of($item->line_total), $quantity, $item->quantity);

            $lines[] = [
                'sale_item_id' => $item->id,
                'product_id' => $item->product_id,
                'quantity' => $quantity,
                'unit_price' => Money::proportion($amount, 1, $quantity),
                'amount' => $amount,
                'unit_cost' => Money::of($item->unit_cost),
                'cost_total' => Money::mul(Money::of($item->unit_cost), $quantity),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function resolveRefund(?Party $party, string $value, string $alreadyPaidPart, array $data): string
    {
        $requested = isset($data['refund_amount']) && $data['refund_amount'] !== null && $data['refund_amount'] !== ''
            ? Money::of((string) $data['refund_amount'])
            : null;

        if (! $party) {
            // Walk-in: nothing was owed and there is no account for credit, so the full value is refunded.
            if ($requested !== null && Money::cmp($requested, $value) !== 0) {
                throw ValidationException::withMessages(['refund_amount' => "Walk-in returns are refunded in full ({$value})."]);
            }

            return $value;
        }

        $refund = $requested ?? '0.00';

        // Cash back only for money actually paid, and only while the shop owes the customer after the return.
        $balanceAfter = Money::sub(Money::of($party->balance), $value);
        $refundable = Money::max('0.00', Money::min($alreadyPaidPart, Money::negate($balanceAfter)));

        if (Money::cmp($refund, $refundable) > 0) {
            throw ValidationException::withMessages([
                'refund_amount' => "The refund cannot exceed {$refundable} (the paid amount the shop owes back after this return).",
            ]);
        }

        return $refund;
    }

    private function recordRefund(SaleReturn $return, ?Party $party, string $amount, PaymentMethod $method): void
    {
        $payment = Payment::create([
            'payment_no' => $this->numbers->next('PAY', $return->returned_at),
            'party_id' => $party?->id,
            'direction' => PaymentPurpose::CustomerRefund->direction(),
            'purpose' => PaymentPurpose::CustomerRefund,
            'method' => $method,
            'amount' => $amount,
            'paid_at' => $return->returned_at,
            'source_type' => $return->getMorphClass(),
            'source_id' => $return->id,
        ]);

        if ($party) {
            $this->ledger->debit($party, LedgerEntryType::CustomerRefund, $amount, $payment, "Refund {$payment->payment_no} for {$return->return_no}", $return->returned_at);
        }
    }
}
