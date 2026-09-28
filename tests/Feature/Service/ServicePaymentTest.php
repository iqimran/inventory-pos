<?php

namespace Tests\Feature\Service;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Service\Concerns\BuildsServiceJobs;
use Tests\TestCase;

/**
 * T031 — service invoice payment, partial payment, due, and customer ledger integration.
 */
class ServicePaymentTest extends TestCase
{
    use BuildsServiceJobs, RefreshDatabase;

    private Product $ic;

    private Product $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpService();
        $this->ic = Product::factory()->withStock(10)->create(['retail_price' => '800.00']);
        $this->connector = Product::factory()->withStock(10)->create(['retail_price' => '200.00']);
    }

    /**
     * A READY job billed IC 800 + connector 200 + service 500 = 1,500.
     */
    private function billedJob(string $paid, bool $deliver = false): ServiceInvoice
    {
        $job = $this->openJob();
        $this->addPart($job, $this->ic, 1);
        $this->addPart($job, $this->connector, 1);
        $this->addCharge($job, '500.00');
        $this->makeReady($job);
        $this->invoice($job, ['paid_amount' => $paid, 'payment_method' => 'MOBILE_BANKING', 'deliver' => $deliver])->assertSessionHasNoErrors();

        return ServiceInvoice::where('service_job_id', $job->id)->sole();
    }

    private function collect(array $data, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->technician)->post('/customer-payments', array_merge([
            'party_id' => $this->customer->id, 'method' => 'CASH', 'date' => today()->toDateString(),
        ], $data));
    }

    private function balance(): string
    {
        return $this->customer->fresh()->balance;
    }

    public function test_fully_paid_invoice()
    {
        $invoice = $this->billedJob('1500.00');

        $this->assertSame('1500.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->due_amount);
        $this->assertSame(PaymentStatus::Paid, $invoice->payment_status);
        $this->assertSame('MOBILE_BANKING', $invoice->payment_method->value);

        $payment = Payment::sole();
        $this->assertSame('SALE_PAYMENT', $payment->purpose->value); // a customer payment
        $this->assertSame('IN', $payment->direction->value);
        $this->assertSame('1500.00', $payment->amount);
        $this->assertSame('1500.00', $payment->allocated_amount);
        $this->assertSame($this->customer->id, $payment->party_id);
        $this->assertTrue($payment->source->is($invoice));
        $this->assertTrue($payment->allocations()->sole()->allocatable->is($invoice));

        // Ledger: receivable posted and settled.
        $entries = PartyLedgerEntry::with('reference')->where('party_id', $this->customer->id)->orderBy('id')->get();
        $this->assertSame([LedgerEntryType::ServiceInvoice, LedgerEntryType::CustomerPayment], $entries->pluck('entry_type')->all());
        $this->assertSame('1500.00', $entries[0]->debit);
        $this->assertSame('1500.00', $entries[1]->credit);
        $this->assertTrue($entries[0]->reference->is($invoice));
        $this->assertSame('0.00', $this->balance());
    }

    public function test_partial_payment_leaves_a_due_on_the_customer_ledger()
    {
        $invoice = $this->billedJob('1000.00');

        $this->assertSame('1000.00', $invoice->paid_amount);
        $this->assertSame('500.00', $invoice->due_amount);
        $this->assertSame(PaymentStatus::Partial, $invoice->payment_status);
        $this->assertSame('500.00', $this->balance());
        $this->assertSame('500.00', app(PartyLedgerService::class)->ledgerBalance($this->customer));
    }

    public function test_unpaid_invoice_is_fully_due()
    {
        $invoice = $this->billedJob('0');

        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertSame('1500.00', $invoice->due_amount);
        $this->assertSame(PaymentStatus::Due, $invoice->payment_status);
        $this->assertNull($invoice->payment_method);
        $this->assertSame(0, Payment::count());
        $this->assertSame('1500.00', $this->balance());

        $entry = PartyLedgerEntry::sole();
        $this->assertSame(LedgerEntryType::ServiceInvoice, $entry->entry_type);
        $this->assertSame('1500.00', $entry->balance_after);
        $this->assertStringContainsString($invoice->invoice_no, $entry->description);
    }

    public function test_device_may_be_delivered_on_due()
    {
        $invoice = $this->billedJob('0', deliver: true);

        $this->assertSame('DELIVERED', $invoice->serviceJob->status->value);
        $this->assertSame('1500.00', $this->balance());
    }

    public function test_collect_the_due_against_the_service_invoice_in_parts()
    {
        $invoice = $this->billedJob('500.00'); // 1000 due

        $this->collect(['service_invoice_id' => $invoice->id, 'amount' => '400.00'])->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('900.00', $invoice->paid_amount);
        $this->assertSame('600.00', $invoice->due_amount);
        $this->assertSame(PaymentStatus::Partial, $invoice->payment_status);
        $this->assertSame('600.00', $this->balance());

        $payment = Payment::latest('id')->first();
        $this->assertTrue($payment->allocations()->sole()->allocatable->is($invoice));
        $this->assertStringContainsString($invoice->invoice_no, PartyLedgerEntry::latest('id')->first()->description);

        $this->collect(['service_invoice_id' => $invoice->id, 'amount' => '600.00'])->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('0.00', $invoice->due_amount);
        $this->assertSame(PaymentStatus::Paid, $invoice->payment_status);
        $this->assertSame('0.00', $this->balance());
    }

    public function test_collection_cannot_exceed_the_invoice_due()
    {
        $invoice = $this->billedJob('1000.00');

        $this->collect(['service_invoice_id' => $invoice->id, 'amount' => '500.01'])->assertSessionHasErrors('amount');
        $this->assertSame('500.00', $invoice->fresh()->due_amount);
        $this->assertSame(1, Payment::count());
    }

    public function test_collection_is_scoped_to_the_customer_and_one_document()
    {
        $invoice = $this->billedJob('0');
        $other = Party::factory()->customer()->create();

        $this->collect(['party_id' => $other->id, 'service_invoice_id' => $invoice->id, 'amount' => '10'])->assertSessionHasErrors('service_invoice_id');

        $sale = $this->dueSale('100.00');
        $this->collect(['sale_id' => $sale->id, 'service_invoice_id' => $invoice->id, 'amount' => '10'])->assertSessionHasErrors('service_invoice_id');

        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
    }

    public function test_on_account_collection_settles_sales_and_service_invoices_oldest_first()
    {
        $this->travel(-3)->days();
        $sale = $this->dueSale('800.00');             // oldest: 800 due
        $this->travelBack();
        $this->travel(-1)->days();
        $invoice = $this->billedJob('1000.00');        // 500 due
        $this->travelBack();
        $laterSale = $this->dueSale('200.00');         // newest: 200 due

        $this->assertSame('1500.00', $this->balance());

        $this->collect(['amount' => '1000.00'])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $sale->fresh()->due_amount);
        $this->assertSame('300.00', $invoice->fresh()->due_amount);
        $this->assertSame(PaymentStatus::Partial, $invoice->fresh()->payment_status);
        $this->assertSame('200.00', $laterSale->fresh()->due_amount);
        $this->assertSame('500.00', $this->balance());

        $payment = Payment::latest('id')->first();
        $this->assertSame('1000.00', $payment->allocated_amount);
        $this->assertSame(['800.00', '200.00'], $payment->allocations()->orderBy('id')->pluck('amount')->all());
    }

    public function test_ledger_and_allocations_reconcile()
    {
        $invoice = $this->billedJob('300.00');
        $this->collect(['service_invoice_id' => $invoice->id, 'amount' => '700.00']);
        $this->collect(['amount' => '200.00']);

        $this->assertSame('300.00', $this->balance());
        $this->assertSame($this->balance(), app(PartyLedgerService::class)->ledgerBalance($this->customer));
        $this->assertSame('300.00', $invoice->fresh()->due_amount);

        $this->artisan('ledger:reconcile')->assertSuccessful();
        $this->artisan('inventory:reconcile')->assertSuccessful();
    }

    public function test_collect_page_lists_due_service_invoices_and_receipt_links_them()
    {
        $invoice = $this->billedJob('1000.00');

        $this->actingAs($this->technician)->get("/customer-payments/create?party_id={$this->customer->id}&service_invoice_id={$invoice->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer-payments/create')
                ->where('serviceInvoiceId', $invoice->id)
                ->has('customer.due_service_invoices', 1)
                ->where('customer.due_service_invoices.0.invoice_no', $invoice->invoice_no)
                ->where('customer.due_service_invoices.0.due_amount', '500.00'));

        $this->collect(['service_invoice_id' => $invoice->id, 'amount' => '500.00']);
        $payment = Payment::latest('id')->first();

        $this->actingAs($this->technician)->get("/customer-payments/{$payment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.data.allocations.0.document.type', 'service_invoice')
                ->where('payment.data.allocations.0.document.number', $invoice->invoice_no));
    }

    public function test_collecting_requires_the_collect_permission()
    {
        $invoice = $this->billedJob('0');
        $technicianOnly = User::factory()->create();
        $technicianOnly->givePermissionTo(Permission::ServiceView->value, Permission::ServiceManage->value);

        $this->collect(['service_invoice_id' => $invoice->id, 'amount' => '100'], $technicianOnly)->assertForbidden();
        $this->assertSame('1500.00', $invoice->fresh()->due_amount);
    }

    private function dueSale(string $amount): Sale
    {
        $product = Product::factory()->withStock(5)->create(['retail_price' => $amount]);

        $this->actingAs($this->technician)->post('/sales', [
            'sale_type' => 'RETAIL',
            'party_id' => $this->customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'paid_amount' => '0',
            'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->first();
    }
}
