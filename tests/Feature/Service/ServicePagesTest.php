<?php

namespace Tests\Feature\Service;

use App\Enums\Permission;
use App\Models\Device;
use App\Models\Product;
use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Service\Concerns\BuildsServiceJobs;
use Tests\TestCase;

/**
 * Service pages render with the data the UI needs (strict mode also fails on any lazy load / N+1).
 */
class ServicePagesTest extends TestCase
{
    use BuildsServiceJobs, RefreshDatabase;

    private Product $ic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpService();
        $this->ic = Product::factory()->withStock(5)->create(['name' => 'Charging IC', 'retail_price' => '800.00']);
    }

    private function invoicedJob(): ServiceJob
    {
        $job = $this->openJob(['technician_id' => $this->technician->id]);
        $this->addPart($job, $this->ic, 1);
        $this->addCharge($job, '500.00');
        $this->makeReady($job);
        $this->invoice($job, ['paid_amount' => '1000.00'])->assertSessionHasNoErrors();

        return $job->fresh();
    }

    public function test_jobs_index_lists_and_filters_jobs()
    {
        $invoiced = $this->invoicedJob();
        $open = $this->openJob();

        $this->actingAs($this->technician)->get('/service/jobs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('service/jobs/index')
                ->has('jobs.data', 2)
                ->has('statuses', 7)
                ->where('technicians.0.id', $this->technician->id)
                ->where('jobs.data.0.job_no', $open->job_no)
                ->where('jobs.data.1.invoice.total', '1300.00')
                ->where('jobs.data.1.technician', $this->technician->name));

        $this->actingAs($this->technician)->get('/service/jobs?status=READY')
            ->assertInertia(fn (Assert $page) => $page->has('jobs.data', 1)->where('jobs.data.0.id', $invoiced->id));

        $this->actingAs($this->technician)->get('/service/jobs?q='.strtolower($open->job_no))
            ->assertInertia(fn (Assert $page) => $page->has('jobs.data', 1)->where('jobs.data.0.id', $open->id));

        $this->actingAs($this->technician)->get('/service/jobs?q='.substr(self::IMEI_A, 2, 10))
            ->assertInertia(fn (Assert $page) => $page->has('jobs.data', 2));
    }

    public function test_jobs_index_query_count_does_not_grow_with_jobs()
    {
        $this->openJob();
        $this->actingAs($this->technician);

        DB::enableQueryLog();
        $this->get('/service/jobs')->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 5) as $ignored) {
            $this->openJob();
        }

        DB::flushQueryLog();
        $this->get('/service/jobs')->assertOk();
        $this->assertSame($few, count(DB::getQueryLog()));
    }

    public function test_create_page_preselects_customer_and_devices()
    {
        $this->actingAs($this->technician)->get("/service/jobs/create?party_id={$this->customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('service/jobs/create')
                ->where('customer.id', $this->customer->id)
                ->has('devices.data', 1)
                ->where('devices.data.0.imei1', self::IMEI_A));
    }

    public function test_job_page_shows_workflow_lines_and_history()
    {
        $job = $this->openJob();
        $this->addPart($job, $this->ic, 2);
        $this->addCharge($job, '500.00', 'Repair / labour');

        $this->actingAs($this->technician)->get("/service/jobs/{$job->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('service/jobs/show')
                ->where('job.data.status', 'RECEIVED')
                ->where('job.data.next_statuses.0.value', 'DIAGNOSING')
                ->where('job.data.device.imei1', self::IMEI_A)
                ->where('job.data.party.name', 'Rahim')
                ->where('job.data.parts.0.product.name', 'Charging IC')
                ->where('job.data.parts.0.stock', 5)
                ->where('job.data.parts.0.consumed_at', null)
                ->where('job.data.parts_total', '1600.00')
                ->where('job.data.service_charge', '500.00')
                ->where('job.data.charges.0.description', 'Repair / labour')
                ->where('job.data.invoice', null)
                ->has('job.data.status_logs', 1)
                ->has('methods', 5)
                ->where('canOverridePrice', false));
    }

    public function test_invoiced_job_page_links_the_invoice()
    {
        $job = $this->invoicedJob();

        $this->actingAs($this->technician)->get("/service/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('job.data.invoice.invoice_no', ServiceInvoice::sole()->invoice_no)
                ->where('job.data.invoice.due_amount', '300.00')
                ->whereNot('job.data.parts.0.consumed_at', null)
                ->has('job.data.status_logs', 5));
    }

    public function test_invoice_page_shows_product_and_service_lines()
    {
        $this->invoicedJob();
        $invoice = ServiceInvoice::sole();

        $this->actingAs($this->technician)->get("/service/invoices/{$invoice->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('service/invoices/show')
                ->where('invoice.data.total', '1300.00')
                ->where('invoice.data.product_total', '800.00')
                ->where('invoice.data.service_total', '500.00')
                ->where('invoice.data.paid_amount', '1000.00')
                ->where('invoice.data.due_amount', '300.00')
                ->where('invoice.data.payment_status', 'PARTIAL')
                ->where('invoice.data.items.0.line_type', 'PRODUCT')
                ->where('invoice.data.items.0.description', 'Charging IC')
                ->where('invoice.data.items.1.line_type', 'SERVICE')
                ->where('invoice.data.job.device.imei1', self::IMEI_A)
                ->has('invoice.data.allocations', 1)
                ->missing('invoice.data.cost_total')      // no purchase access
                ->missing('invoice.data.items.0.unit_cost'));

        $this->actingAs($this->admin())->get("/service/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page->has('invoice.data.cost_total')->has('invoice.data.items.0.unit_cost'));
    }

    public function test_printable_invoice_separates_parts_and_service_charges()
    {
        config(['shop.name' => 'IQ Mobile', 'shop.phone' => '01700000000', 'shop.receipt_footer' => 'Warranty 30 days']);
        $job = $this->invoicedJob();
        $invoice = ServiceInvoice::sole();

        $this->actingAs($this->technician)->get("/service/invoices/{$invoice->id}/print")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('service/invoices/print')
                ->where('shop.name', 'IQ Mobile')
                ->where('shop.phone', '01700000000')
                ->where('shop.receipt_footer', 'Warranty 30 days')
                ->where('invoice.data.invoice_no', $invoice->invoice_no)
                ->where('invoice.data.job.job_no', $job->job_no)
                ->has('invoice.data.job.received_at')
                ->where('invoice.data.job.complaint', 'No charging')
                ->where('invoice.data.job.device.imei1', self::IMEI_A)
                ->where('invoice.data.party.name', 'Rahim')
                ->has('invoice.data.items', 2)
                ->where('invoice.data.items.0.line_type', 'PRODUCT')
                ->where('invoice.data.items.0.description', 'Charging IC')
                ->where('invoice.data.items.0.line_subtotal', '800.00')
                ->where('invoice.data.items.1.line_type', 'SERVICE')
                ->where('invoice.data.items.1.line_subtotal', '500.00')
                ->where('invoice.data.product_total', '800.00')
                ->where('invoice.data.service_total', '500.00')
                ->where('invoice.data.total', '1300.00')
                ->where('invoice.data.paid_amount', '1000.00')
                ->where('invoice.data.due_amount', '300.00')
                ->missing('invoice.data.allocations')        // the slip prints only the total paid
                ->missing('invoice.data.items.0.unit_cost')); // customer copy never shows cost

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get("/service/invoices/{$invoice->id}/print")->assertForbidden();
    }

    public function test_invoice_index()
    {
        $this->invoicedJob();

        $this->actingAs($this->technician)->get('/service/invoices?status=PARTIAL')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('service/invoices/index')
                ->has('invoices.data', 1)
                ->where('invoices.data.0.product_total', '800.00')
                ->where('invoices.data.0.service_total', '500.00'));

        $this->actingAs($this->technician)->get('/service/invoices?status=PAID')
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
    }

    public function test_party_statement_shows_the_service_invoice()
    {
        $this->invoicedJob();

        $this->actingAs($this->admin())->get("/parties/{$this->customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statement.entries.0.entry_type', 'SERVICE_INVOICE')
                ->where('statement.entries.0.reference_type', 'service_invoice')
                ->where('statement.entries.0.debit', '1300.00')
                ->where('statement.closing_balance', '300.00'));
    }

    public function test_service_pages_need_service_view()
    {
        $job = $this->invoicedJob();
        $outsider = User::factory()->create();
        $outsider->givePermissionTo(Permission::SalesView->value);

        foreach (['/service/jobs', "/service/jobs/{$job->id}", '/service/devices', '/service/invoices', '/service/invoices/'.ServiceInvoice::sole()->id] as $url) {
            $this->actingAs($outsider)->get($url)->assertForbidden();
        }
    }

    public function test_parts_search_is_available_to_service_staff()
    {
        $technicianOnly = User::factory()->create();
        $technicianOnly->givePermissionTo(Permission::ServiceView->value, Permission::ServiceManage->value);

        $this->actingAs($technicianOnly)->getJson('/pos/products?q=Charging')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Charging IC');

        $this->actingAs(User::factory()->create())->getJson('/pos/products?q=Charging')->assertForbidden();
        $this->assertSame(1, Device::count());
    }
}
