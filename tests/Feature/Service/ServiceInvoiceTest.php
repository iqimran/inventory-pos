<?php

namespace Tests\Feature\Service;

use App\Domain\Inventory\StockService;
use App\Enums\InvoiceLineType;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\ServiceJobStatus;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceItem;
use App\Models\ServiceJob;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Service\Concerns\BuildsServiceJobs;
use Tests\TestCase;

/**
 * T030 — combined service invoice: PRODUCT lines (parts, stock out) + SERVICE lines (labour, no stock).
 */
class ServiceInvoiceTest extends TestCase
{
    use BuildsServiceJobs, RefreshDatabase;

    private ServiceJob $job;

    private Product $ic;

    private Product $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpService();
        $this->ic = Product::factory()->withStock(10)->create(['name' => 'Charging IC', 'retail_price' => '800.00', 'purchase_price' => '550.00']);
        $this->connector = Product::factory()->withStock(10)->create(['name' => 'Type-C connector', 'retail_price' => '200.00', 'purchase_price' => '90.00']);
        $this->job = $this->openJob();
    }

    /**
     * The reference scenario: IC 800 + connector 200 (PRODUCT) + service charge 500 (SERVICE) = 1,500.
     */
    private function referenceJob(): ServiceJob
    {
        $this->addPart($this->job, $this->ic, 1)->assertSessionHasNoErrors();
        $this->addPart($this->job, $this->connector, 1)->assertSessionHasNoErrors();
        $this->addCharge($this->job, '500.00', 'Repair / labour')->assertSessionHasNoErrors();

        return $this->makeReady($this->job);
    }

    public function test_combined_invoice_totals_1500_with_product_and_service_lines()
    {
        $this->referenceJob();

        $this->invoice($this->job, ['paid_amount' => '1500.00'])->assertSessionHasNoErrors();

        $invoice = ServiceInvoice::sole();
        $this->assertMatchesRegularExpression('/^SRV-\d{6}-000001$/', $invoice->invoice_no);
        $this->assertSame($this->job->id, $invoice->service_job_id);
        $this->assertSame($this->customer->id, $invoice->party_id);
        $this->assertSame('1500.00', $invoice->subtotal);
        $this->assertSame('0.00', $invoice->discount);
        $this->assertSame('1500.00', $invoice->total);
        $this->assertSame($this->technician->id, $invoice->created_by);
        $this->assertNotNull($invoice->invoiced_at);

        $lines = $invoice->items()->orderBy('id')->get();
        $this->assertSame(
            [['PRODUCT', 'Charging IC', '800.00'], ['PRODUCT', 'Type-C connector', '200.00'], ['SERVICE', 'Repair / labour', '500.00']],
            $lines->map(fn (ServiceInvoiceItem $line) => [$line->line_type->value, $line->description, $line->line_total])->all(),
        );
        $this->assertSame($this->ic->id, $lines[0]->product_id);
        $this->assertNull($lines[2]->product_id);
        $this->assertSame(1, $lines[2]->quantity);
    }

    public function test_product_and_service_revenue_are_classified_separately()
    {
        $this->referenceJob();
        $this->invoice($this->job, ['paid_amount' => '1500.00'])->assertSessionHasNoErrors();

        $invoice = ServiceInvoice::sole();
        $this->assertSame('1000.00', $invoice->product_total);
        $this->assertSame('500.00', $invoice->service_total);
        $this->assertSame('1500.00', bcadd($invoice->product_total, $invoice->service_total, 2));

        // Revenue by line type straight from the lines (what reports will aggregate).
        $this->assertSame('1000.00', number_format((float) $invoice->productLines()->sum('line_total'), 2, '.', ''));
        $this->assertSame('500.00', number_format((float) $invoice->serviceLines()->sum('line_total'), 2, '.', ''));
        $this->assertSame(2, $invoice->productLines()->count());
        $this->assertSame(1, $invoice->serviceLines()->count());
    }

    public function test_invoicing_consumes_parts_with_service_part_out_movements()
    {
        $this->referenceJob();
        // Cost snapshot = moving average, or the purchase price while no costed stock has arrived (as for sales).
        $icCost = app(StockService::class)->averageCost($this->ic) ?? $this->ic->purchase_price;
        $connectorCost = app(StockService::class)->averageCost($this->connector) ?? $this->connector->purchase_price;
        $this->assertSame(['550.00', '90.00'], [$icCost, $connectorCost]);

        $this->assertSame(10, $this->stock($this->ic)); // still untouched while READY
        $this->invoice($this->job)->assertSessionHasNoErrors();

        $this->assertSame(9, $this->stock($this->ic));
        $this->assertSame(9, $this->stock($this->connector));
        $this->assertSame(9, app(StockService::class)->ledgerBalance($this->ic));

        $movements = StockMovement::with('reference')->where('type', 'SERVICE_PART_OUT')->orderBy('id')->get();
        $this->assertCount(2, $movements); // parts only: the service charge never moves stock
        $this->assertSame([-1, -1], $movements->pluck('quantity')->all());
        $this->assertTrue($movements[0]->reference->is($this->job));
        $this->assertStringContainsString($this->job->job_no, $movements[0]->notes);
        $this->assertStringContainsString(ServiceInvoice::sole()->invoice_no, $movements[0]->notes);
        $this->assertSame($this->technician->id, $movements[0]->created_by);

        // Parts are marked consumed with their cost snapshot and movement.
        $icPart = $this->job->items()->where('product_id', $this->ic->id)->sole();
        $this->assertNotNull($icPart->consumed_at);
        $this->assertSame($movements[0]->id, $icPart->stock_movement_id);
        $this->assertSame($icCost, $icPart->cost_snapshot);

        $invoice = ServiceInvoice::sole();
        $this->assertSame($icCost, $invoice->productLines()->where('product_id', $this->ic->id)->sole()->unit_cost);
        $this->assertSame(bcadd($icCost, $connectorCost, 2), $invoice->cost_total);
        $this->assertNull($invoice->serviceLines()->sole()->unit_cost);
    }

    public function test_service_only_invoice_moves_no_stock()
    {
        $this->addCharge($this->job, '300.00', 'Software flash');
        $this->makeReady($this->job);
        $movementsBefore = StockMovement::count();

        $this->invoice($this->job, ['paid_amount' => '300.00'])->assertSessionHasNoErrors();

        $invoice = ServiceInvoice::sole();
        $this->assertSame('300.00', $invoice->total);
        $this->assertSame('0.00', $invoice->product_total);
        $this->assertSame('300.00', $invoice->service_total);
        $this->assertSame($movementsBefore, StockMovement::count());
    }

    public function test_parts_only_invoice()
    {
        $this->addPart($this->job, $this->connector, 2);
        $this->makeReady($this->job);

        $this->invoice($this->job)->assertSessionHasNoErrors();

        $invoice = ServiceInvoice::sole();
        $this->assertSame('400.00', $invoice->total);
        $this->assertSame('400.00', $invoice->product_total);
        $this->assertSame('0.00', $invoice->service_total);
        $this->assertSame(8, $this->stock($this->connector));
    }

    public function test_invoice_discount_is_spread_exactly_across_product_and_service_revenue()
    {
        $this->referenceJob();

        $this->invoice($this->job, ['discount' => '100.00'])->assertSessionHasNoErrors();

        $invoice = ServiceInvoice::sole();
        $this->assertSame('1500.00', $invoice->subtotal);
        $this->assertSame('100.00', $invoice->discount);
        $this->assertSame('1400.00', $invoice->total);
        // 100 spread 800:200:500 → 53.34 / 13.33 / 33.33 (largest remainder; equal remainders go to earlier lines).
        $this->assertSame(['53.34', '13.33', '33.33'], $invoice->items()->orderBy('id')->pluck('discount_share')->all());
        $this->assertSame('933.33', $invoice->product_total);
        $this->assertSame('466.67', $invoice->service_total);
        $this->assertSame($invoice->total, bcadd($invoice->product_total, $invoice->service_total, 2));
    }

    public function test_discount_cannot_exceed_subtotal()
    {
        $this->referenceJob();

        $this->invoice($this->job, ['discount' => '1500.01'])->assertSessionHasErrors('discount');
        $this->assertSame(0, ServiceInvoice::count());
    }

    public function test_insufficient_stock_rolls_everything_back()
    {
        $this->addPart($this->job, $this->ic, 11); // only 10 in stock
        $this->addPart($this->job, $this->connector, 1);
        $this->addCharge($this->job, '500.00');
        $this->makeReady($this->job);
        $movementsBefore = StockMovement::count();

        $this->invoice($this->job, ['paid_amount' => '100.00'])->assertSessionHasErrors('quantity');

        $this->assertSame(0, ServiceInvoice::count());
        $this->assertSame(0, ServiceInvoiceItem::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, PartyLedgerEntry::count());
        $this->assertSame($movementsBefore, StockMovement::count());
        $this->assertSame(10, $this->stock($this->ic));
        $this->assertSame(10, $this->stock($this->connector));
        $this->assertTrue($this->job->items()->get()->every(fn ($part) => $part->consumed_at === null));
        $this->assertSame('0.00', $this->customer->fresh()->balance);

        // After fixing the quantity the job can be invoiced; the rolled-back number is reused.
        $this->moveTo($this->job, 'IN_PROGRESS');
        $part = $this->job->items()->where('product_id', $this->ic->id)->sole();
        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/parts/{$part->id}", ['quantity' => 1])->assertSessionHasNoErrors();
        $this->moveTo($this->job, 'READY');
        $this->invoice($this->job)->assertSessionHasNoErrors();
        $this->assertStringEndsWith('-000001', ServiceInvoice::sole()->invoice_no);
    }

    public function test_only_ready_jobs_with_lines_can_be_invoiced_once()
    {
        $this->addCharge($this->job, '500.00');

        $this->invoice($this->job)->assertSessionHasErrors('job'); // RECEIVED
        $this->moveTo($this->job, 'DIAGNOSING');
        $this->invoice($this->job)->assertSessionHasErrors('job');

        $this->makeReadyFromDiagnosing();
        $this->invoice($this->job)->assertSessionHasNoErrors();
        $this->invoice($this->job)->assertSessionHasErrors('job'); // already invoiced

        $this->assertSame(1, ServiceInvoice::count());
    }

    public function test_a_job_without_parts_or_charges_cannot_be_invoiced()
    {
        $this->makeReady($this->job);

        $this->invoice($this->job)->assertSessionHasErrors('job');
        $this->assertSame(0, ServiceInvoice::count());
    }

    public function test_cancelled_jobs_cannot_be_invoiced()
    {
        $this->addCharge($this->job, '500.00');
        $this->moveTo($this->job, 'CANCELLED', ['reason' => 'Declined']);

        $this->invoice($this->job)->assertSessionHasErrors('job');
    }

    public function test_invoice_and_deliver_in_one_step()
    {
        $this->referenceJob();

        $this->invoice($this->job, ['paid_amount' => '1500.00', 'deliver' => true])->assertSessionHasNoErrors();

        $job = $this->job->fresh();
        $this->assertSame(ServiceJobStatus::Delivered, $job->status);
        $this->assertNotNull($job->delivered_at);
        $this->assertSame('DELIVERED', $job->statusLogs()->latest('id')->first()->to_status->value);
    }

    public function test_redirects_to_the_invoice()
    {
        $this->referenceJob();

        $this->invoice($this->job)->assertRedirect('/service/invoices/'.ServiceInvoice::sole()->id);
    }

    public function test_invoices_are_never_deleted()
    {
        $this->referenceJob();
        $this->invoice($this->job);

        $this->expectException(LogicException::class);
        ServiceInvoice::sole()->delete();
    }

    public function test_only_service_managers_can_invoice()
    {
        $this->referenceJob();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ServiceView->value, Permission::SalesCreate->value);

        $this->invoice($this->job, as: $viewer)->assertForbidden();
        $this->assertSame(0, ServiceInvoice::count());
        $this->assertSame(10, $this->stock($this->ic));
    }

    public function test_invoice_payload_validation()
    {
        $this->referenceJob();

        $this->invoice($this->job, ['payment_method' => 'BITCOIN'])->assertSessionHasErrors('payment_method');
        $this->invoice($this->job, ['paid_amount' => '-1'])->assertSessionHasErrors('paid_amount');
        $this->invoice($this->job, ['paid_amount' => '1500.01'])->assertSessionHasErrors('paid_amount');

        $this->assertSame(0, ServiceInvoice::count());
        $this->assertSame(PaymentStatus::class, PaymentStatus::Due::class); // sanity: enum loaded
        $this->assertSame(InvoiceLineType::Product, InvoiceLineType::from('PRODUCT'));
    }

    private function makeReadyFromDiagnosing(): void
    {
        $this->moveTo($this->job, 'WAITING_FOR_APPROVAL', ['diagnosis' => 'Board fault'])->assertSessionHasNoErrors();
        $this->moveTo($this->job, 'IN_PROGRESS', ['approved_amount' => '500'])->assertSessionHasNoErrors();
        $this->moveTo($this->job, 'READY')->assertSessionHasNoErrors();
    }
}
