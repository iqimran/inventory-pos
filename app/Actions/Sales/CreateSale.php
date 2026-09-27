<?php

namespace App\Actions\Sales;

use App\Domain\Inventory\Data\StockMovementData;
use App\Domain\Inventory\StockService;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Domain\Sales\SaleCalculator;
use App\Domain\Sales\SaleSettlement;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Enums\StockMovementType;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Records a POS sale atomically: invoice, stock-out movements (with cost snapshot), customer
 * receivable in the ledger, and the payment received at the counter.
 */
class CreateSale
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PaymentAllocator $allocator,
        private readonly StockService $stock,
        private readonly SaleCalculator $calculator,
        private readonly SaleSettlement $settlement,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{sale_type: string, party_id?: ?int, discount?: ?string, notes?: ?string,
     *               items: list<array{product_id: int, quantity: int, unit_price?: ?string, discount?: ?string}>,
     *               paid_amount?: ?string, payment_method?: ?string, tendered_amount?: ?string}  $data
     * @param  bool  $allowPriceOverride  whether the cashier may charge a price other than the list price
     *
     * @throws ValidationException
     */
    public function handle(array $data, bool $allowPriceOverride = false): Sale
    {
        return DB::transaction(function () use ($data, $allowPriceOverride): Sale {
            $type = SaleType::from($data['sale_type']);
            $soldAt = now();

            // Lock order: party first, then stock (inside StockService).
            $party = ! empty($data['party_id']) ? $this->ledger->lock($data['party_id']) : null;

            $lines = $this->priceLines($data['items'], $type, $allowPriceOverride);
            $totals = $this->totals($lines, $data['discount'] ?? '0');
            $paid = Money::of($data['paid_amount'] ?? '0');
            $due = Money::sub($totals['total'], $paid);

            if (Money::isNegative($due)) {
                throw ValidationException::withMessages(['paid_amount' => 'The paid amount cannot exceed the sale total.']);
            }

            if (Money::isPositive($due) && ! $party) {
                throw ValidationException::withMessages(['party_id' => 'Select a customer to sell on due; walk-in sales must be paid in full.']);
            }

            $tendered = isset($data['tendered_amount']) && $data['tendered_amount'] !== '' ? Money::of($data['tendered_amount']) : null;

            if ($tendered !== null && Money::cmp($tendered, $paid) < 0) {
                throw ValidationException::withMessages(['tendered_amount' => 'The amount tendered cannot be less than the amount paid.']);
            }

            $method = PaymentMethod::from($data['payment_method'] ?? PaymentMethod::Cash->value);

            $sale = Sale::create([
                'invoice_no' => $this->numbers->next('SALE', $soldAt),
                'party_id' => $party?->id,
                'sale_type' => $type,
                'status' => SaleStatus::Completed,
                'sold_at' => $soldAt,
                'subtotal' => $totals['subtotal'],
                'items_discount' => $totals['items_discount'],
                'discount' => $totals['discount'],
                'total' => $totals['total'],
                'due_amount' => $totals['total'],
                'payment_status' => PaymentStatus::Due,
                'payment_method' => Money::isPositive($paid) ? $method : null,
                'tendered_amount' => $tendered,
                'change_amount' => $tendered !== null ? Money::sub($tendered, $paid) : '0.00',
                'notes' => $data['notes'] ?? null,
            ]);

            // Stock out; StockService stamps each movement with the current average cost (the snapshot).
            $movements = $this->stock->recordMany(array_map(fn (array $line) => new StockMovementData(
                productId: $line['product_id'],
                type: StockMovementType::SaleOut,
                quantity: $line['quantity'],
                reference: $sale,
                notes: $sale->invoice_no,
                occurredAt: $soldAt,
            ), $lines));

            $costTotal = '0.00';

            foreach ($lines as $index => $line) {
                $unitCost = Money::of($movements[$index]->unit_cost);
                $lineCost = Money::mul($unitCost, $line['quantity']);
                $costTotal = Money::add($costTotal, $lineCost);

                $sale->items()->create([
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'list_price' => $line['list_price'],
                    'unit_price' => $line['unit_price'],
                    'price_overridden' => $line['price_overridden'],
                    ...$totals['lines'][$index],
                    'unit_cost' => $unitCost,
                    'cost_total' => $lineCost,
                ]);
            }

            $sale->forceFill(['cost_total' => $costTotal])->save();

            if ($party && Money::isPositive($totals['total'])) {
                $this->ledger->debit($party, LedgerEntryType::Sale, $totals['total'], $sale, "Sale {$sale->invoice_no}", $soldAt);
            }

            if (Money::isPositive($paid)) {
                $this->recordPayment($sale, $party, $paid, $method);
            }

            return $this->settlement->refresh($sale);
        }, 3);
    }

    /**
     * Resolve each line's price from the product (never trusting client prices unless overrides are allowed).
     *
     * @param  list<array{product_id: int, quantity: int, unit_price?: ?string, discount?: ?string}>  $items
     * @return list<array{product_id: int, quantity: int, list_price: string, unit_price: string, price_overridden: bool, discount: string}>
     *
     * @throws ValidationException
     */
    private function priceLines(array $items, SaleType $type, bool $allowPriceOverride): array
    {
        $products = Product::whereKey(array_column($items, 'product_id'))->get()->keyBy('id');
        $lines = [];

        foreach ($items as $index => $item) {
            /** @var Product|null $product */
            $product = $products->get($item['product_id']);

            if (! $product || ! $product->is_active) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => 'This product is not available for sale.']);
            }

            $listPrice = Money::of($type->listPrice($product));
            $requested = isset($item['unit_price']) && $item['unit_price'] !== '' ? Money::of($item['unit_price']) : $listPrice;
            $overridden = Money::cmp($requested, $listPrice) !== 0;

            if ($overridden && ! $allowPriceOverride) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => "You are not allowed to change the {$type->label()} price of {$product->name} ({$listPrice}).",
                ]);
            }

            $lines[] = [
                'product_id' => $product->id,
                'quantity' => (int) $item['quantity'],
                'list_price' => $listPrice,
                'unit_price' => $requested,
                'price_overridden' => $overridden,
                'discount' => Money::of($item['discount'] ?? '0'),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function totals(array $lines, string $discount): array
    {
        foreach ($lines as $index => $line) {
            if (Money::cmp($line['discount'], Money::mul($line['unit_price'], $line['quantity'])) > 0) {
                throw ValidationException::withMessages(["items.{$index}.discount" => 'The line discount cannot exceed the line amount.']);
            }
        }

        try {
            return $this->calculator->totals($lines, $discount);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['discount' => 'The invoice discount cannot exceed the discounted subtotal.']);
        }
    }

    private function recordPayment(Sale $sale, ?Party $party, string $amount, PaymentMethod $method): void
    {
        $payment = Payment::create([
            'payment_no' => $this->numbers->next('PAY', $sale->sold_at),
            'party_id' => $party?->id,
            'direction' => PaymentPurpose::SalePayment->direction(),
            'purpose' => PaymentPurpose::SalePayment,
            'method' => $method,
            'amount' => $amount,
            'paid_at' => $sale->sold_at,
            'source_type' => $sale->getMorphClass(),
            'source_id' => $sale->id,
        ]);

        $this->allocator->allocate($payment, $sale, $amount);

        if ($party) {
            $this->ledger->credit($party, LedgerEntryType::CustomerPayment, $amount, $payment, "Payment {$payment->payment_no} for {$sale->invoice_no}", $sale->sold_at);
        }
    }
}
