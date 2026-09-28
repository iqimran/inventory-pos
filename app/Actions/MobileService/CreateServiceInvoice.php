<?php

namespace App\Actions\MobileService;

use App\Domain\Inventory\Data\StockMovementData;
use App\Domain\Inventory\StockService;
use App\Domain\MobileService\ServiceInvoiceSettlement;
use App\Domain\MobileService\ServiceJobGuard;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Domain\Sales\SaleCalculator;
use App\Enums\InvoiceLineType;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\ServiceJobStatus;
use App\Enums\StockMovementType;
use App\Models\Party;
use App\Models\Payment;
use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use App\Models\ServiceJobCharge;
use App\Models\ServiceJobItem;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Bills a READY job in one transaction — the moment its parts are "charged":
 * - one invoice combining PRODUCT lines (the job's parts) and SERVICE lines (its charges);
 * - SERVICE_PART_OUT stock movements for the parts (cost snapshotted), parts locked as consumed;
 * - the customer's receivable (SERVICE_INVOICE debit) and any payment received (credit);
 * - optionally, delivery of the device.
 *
 * Service lines never create stock movements. Everything succeeds or fails together.
 */
class CreateServiceInvoice
{
    public function __construct(
        private readonly PartyLedgerService $ledger,
        private readonly PaymentAllocator $allocator,
        private readonly StockService $stock,
        private readonly SaleCalculator $calculator,
        private readonly ServiceInvoiceSettlement $settlement,
        private readonly ServiceJobGuard $guard,
        private readonly ChangeServiceJobStatus $changeStatus,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{discount?: ?string, paid_amount?: ?string, payment_method?: ?string, notes?: ?string, deliver?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(ServiceJob $job, array $data): ServiceInvoice
    {
        return DB::transaction(function () use ($job, $data): ServiceInvoice {
            // Lock order: party, then job, then stock (inside StockService). A job's customer never changes.
            $party = $this->ledger->lock($job->party_id);
            $job = $this->guard->lock($job);

            if ($this->guard->isInvoiced($job)) {
                throw ValidationException::withMessages(['job' => "Job {$job->job_no} has already been invoiced."]);
            }

            if ($job->status !== ServiceJobStatus::Ready) {
                throw ValidationException::withMessages(['job' => "Only a job that is Ready can be invoiced (job {$job->job_no} is {$job->status->label()})."]);
            }

            $parts = $job->items()->with('product:id,name,sku')->orderBy('id')->get();
            $charges = $job->charges()->orderBy('id')->get();

            if ($parts->isEmpty() && $charges->isEmpty()) {
                throw ValidationException::withMessages(['job' => 'Add parts or a service charge before invoicing.']);
            }

            $lines = $this->lines($parts->all(), $charges->all());
            $totals = $this->totals($lines, $data['discount'] ?? '0');
            $paid = Money::of($data['paid_amount'] ?? '0');

            if (Money::cmp($paid, $totals['total']) > 0) {
                throw ValidationException::withMessages(['paid_amount' => 'The paid amount cannot exceed the invoice total.']);
            }

            $method = PaymentMethod::from($data['payment_method'] ?? PaymentMethod::Cash->value);
            $invoicedAt = now();

            $invoice = ServiceInvoice::create([
                'invoice_no' => $this->numbers->next('SRV', $invoicedAt),
                'service_job_id' => $job->id,
                'party_id' => $party->id,
                'status' => SaleStatus::Completed,
                'invoiced_at' => $invoicedAt,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'total' => $totals['total'],
                'due_amount' => $totals['total'],
                'payment_status' => PaymentStatus::Due,
                'payment_method' => Money::isPositive($paid) ? $method : null,
                'notes' => $data['notes'] ?? null,
            ]);

            $unitCosts = $this->consumeParts($job, $invoice, $parts->all(), $invoicedAt);
            $productTotal = '0.00';
            $serviceTotal = '0.00';
            $costTotal = '0.00';

            foreach ($lines as $index => $line) {
                $computed = $totals['lines'][$index];
                $unitCost = $line['type'] === InvoiceLineType::Product ? $unitCosts[$line['part_id']] : null;
                $lineCost = $unitCost !== null ? Money::mul($unitCost, $line['quantity']) : '0.00';

                $invoice->items()->create([
                    'line_type' => $line['type'],
                    'product_id' => $line['product_id'],
                    'service_job_item_id' => $line['part_id'],
                    'service_job_charge_id' => $line['charge_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_subtotal' => $computed['line_subtotal'],
                    'discount_share' => $computed['discount_share'],
                    'line_total' => $computed['line_total'],
                    'unit_cost' => $unitCost,
                    'cost_total' => $lineCost,
                ]);

                if ($line['type'] === InvoiceLineType::Product) {
                    $productTotal = Money::add($productTotal, $computed['line_total']);
                } else {
                    $serviceTotal = Money::add($serviceTotal, $computed['line_total']);
                }

                $costTotal = Money::add($costTotal, $lineCost);
            }

            $invoice->forceFill(['product_total' => $productTotal, 'service_total' => $serviceTotal, 'cost_total' => $costTotal])->save();

            if (Money::isPositive($totals['total'])) {
                $this->ledger->debit($party, LedgerEntryType::ServiceInvoice, $totals['total'], $invoice, "Service invoice {$invoice->invoice_no} (job {$job->job_no})", $invoicedAt);
            }

            if (Money::isPositive($paid)) {
                $this->recordPayment($invoice, $party, $paid, $method);
            }

            $this->settlement->refresh($invoice);

            if (! empty($data['deliver'])) {
                $this->changeStatus->handle($job, ServiceJobStatus::Delivered);
            }

            return $invoice;
        }, 3);
    }

    /**
     * PRODUCT lines first (parts), then SERVICE lines (charges).
     *
     * @param  list<ServiceJobItem>  $parts
     * @param  list<ServiceJobCharge>  $charges
     * @return list<array{type: InvoiceLineType, product_id: ?int, part_id: ?int, charge_id: ?int, description: string, quantity: int, unit_price: string}>
     */
    private function lines(array $parts, array $charges): array
    {
        $lines = [];

        foreach ($parts as $part) {
            $lines[] = [
                'type' => InvoiceLineType::Product,
                'product_id' => $part->product_id,
                'part_id' => $part->id,
                'charge_id' => null,
                'description' => $part->product->name,
                'quantity' => $part->quantity,
                'unit_price' => Money::of($part->unit_price),
            ];
        }

        foreach ($charges as $charge) {
            $lines[] = [
                'type' => InvoiceLineType::Service,
                'product_id' => null,
                'part_id' => null,
                'charge_id' => $charge->id,
                'description' => $charge->description,
                'quantity' => 1,
                'unit_price' => Money::of($charge->amount),
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
        try {
            // The invoice discount is spread over all lines, so product and service revenue stay exact.
            return $this->calculator->totals($lines, $discount);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot exceed the invoice subtotal.']);
        }
    }

    /**
     * Record SERVICE_PART_OUT for every part and mark it consumed with its cost snapshot.
     *
     * @param  list<ServiceJobItem>  $parts
     * @return array<int, string> part id => unit cost
     *
     * @throws ValidationException when stock is insufficient
     */
    private function consumeParts(ServiceJob $job, ServiceInvoice $invoice, array $parts, \DateTimeInterface $at): array
    {
        if ($parts === []) {
            return [];
        }

        $movements = $this->stock->recordMany(array_map(fn (ServiceJobItem $part) => new StockMovementData(
            productId: $part->product_id,
            type: StockMovementType::ServicePartOut,
            quantity: $part->quantity,
            reference: $job,
            notes: "{$job->job_no} / {$invoice->invoice_no}",
            occurredAt: $at,
        ), $parts));

        $unitCosts = [];

        foreach ($parts as $index => $part) {
            $unitCost = Money::of($movements[$index]->unit_cost);
            $unitCosts[$part->id] = $unitCost;

            $part->update([
                'cost_snapshot' => $unitCost,
                'consumed_at' => $at,
                'stock_movement_id' => $movements[$index]->id,
            ]);
        }

        return $unitCosts;
    }

    private function recordPayment(ServiceInvoice $invoice, Party $party, string $amount, PaymentMethod $method): void
    {
        $payment = Payment::create([
            'payment_no' => $this->numbers->next('PAY', $invoice->invoiced_at),
            'party_id' => $party->id,
            'direction' => PaymentPurpose::SalePayment->direction(),
            'purpose' => PaymentPurpose::SalePayment,
            'method' => $method,
            'amount' => $amount,
            'paid_at' => $invoice->invoiced_at,
            'source_type' => $invoice->getMorphClass(),
            'source_id' => $invoice->id,
        ]);

        $this->allocator->allocate($payment, $invoice, $amount);
        $this->ledger->credit($party, LedgerEntryType::CustomerPayment, $amount, $payment, "Payment {$payment->payment_no} for {$invoice->invoice_no}", $invoice->invoiced_at);
    }
}
