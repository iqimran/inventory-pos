<?php

namespace Tests\Feature\Reports;

use App\Actions\MobileService\ChangeServiceJobStatus;
use App\Actions\MobileService\CreateServiceInvoice;
use App\Actions\MobileService\CreateServiceJob;
use App\Actions\MobileService\SaveServiceJobCharge;
use App\Actions\MobileService\SaveServiceJobPart;
use App\Actions\Sales\CreateSale;
use App\Actions\Sales\CreateSaleReturn;
use App\Domain\Reporting\ReportPeriod;
use App\Domain\Reporting\RevenueReport;
use App\Enums\ServiceJobStatus;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * T041–T043: PRODUCT, SERVICE and combined revenue — including the critical combined service invoice.
 */
#[Group('critical')]
class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Party $customer;

    private Product $ic;

    private Product $connector;

    protected function setUp(): void
    {
        parent::setUp();

        config(['reports.timezone' => 'UTC']);
        $this->travelTo('2026-09-15 10:00:00');
        $this->admin = $this->admin();
        $this->actingAs($this->admin);
        $this->customer = Party::factory()->customer()->create();
        $this->ic = Product::factory()->withStock(50)->create(['name' => 'IC', 'retail_price' => '800.00', 'purchase_price' => '500.00']);
        $this->connector = Product::factory()->withStock(50)->create(['name' => 'Connector', 'retail_price' => '200.00', 'purchase_price' => '80.00']);
    }

    /**
     * PRODUCT: IC 800 + Connector 200; SERVICE: Repair 500.
     */
    private function criticalServiceInvoice(string $discount = '0'): ServiceInvoice
    {
        $job = app(CreateServiceJob::class)->handle(['party_id' => $this->customer->id, 'device' => ['brand' => 'Samsung', 'model' => 'A52'], 'complaint' => 'No charging']);
        app(SaveServiceJobPart::class)->handle($job, ['product_id' => $this->ic->id, 'quantity' => 1]);
        app(SaveServiceJobPart::class)->handle($job, ['product_id' => $this->connector->id, 'quantity' => 1]);
        app(SaveServiceJobCharge::class)->handle($job, ['description' => 'Repair', 'amount' => '500']);

        foreach ([ServiceJobStatus::Diagnosing, ServiceJobStatus::WaitingForApproval, ServiceJobStatus::InProgress, ServiceJobStatus::Ready] as $status) {
            app(ChangeServiceJobStatus::class)->handle($job, $status, ['diagnosis' => 'IC fault', 'approved_amount' => '1500']);
        }

        return app(CreateServiceInvoice::class)->handle($job, ['discount' => $discount, 'paid_amount' => '0', 'payment_method' => 'CASH']);
    }

    private function sale(Product $product, int $quantity, string $discount = '0'): Sale
    {
        return app(CreateSale::class)->handle([
            'sale_type' => 'RETAIL', 'party_id' => $this->customer->id, 'discount' => $discount,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]], 'paid_amount' => '0', 'payment_method' => 'CASH',
        ]);
    }

    private function totals(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return app(RevenueReport::class)->totals(ReportPeriod::make($from, $to));
    }

    public function test_critical_combined_service_invoice_is_split_not_double_counted()
    {
        $invoice = $this->criticalServiceInvoice();
        $this->assertSame('1500.00', $invoice->total);

        $totals = $this->totals();

        $this->assertSame('1000.00', $totals['product']['net']);        // IC 800 + Connector 200
        $this->assertSame('1000.00', $totals['product']['service_parts']);
        $this->assertSame('0.00', $totals['product']['pos_sales']);
        $this->assertSame('500.00', $totals['service']['revenue']);      // Repair 500 only
        $this->assertSame('1500.00', $totals['combined']);               // = 1,000 + 500, not 1,500 + 500
        $this->assertSame(2, $totals['product']['quantity']['net']);
        $this->assertSame(1, $totals['service']['invoices']);

        // Independent check from document totals agrees.
        $this->assertSame('1500.00', $totals['documents']['total']);
        $this->assertTrue($totals['documents']['matches']);
    }

    public function test_critical_invoice_through_the_three_report_pages()
    {
        $this->criticalServiceInvoice();
        $query = '?from=2026-09-01&to=2026-09-30';

        $this->get('/reports/product-revenue'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('reports/product-revenue')->where('totals.net', '1000.00'));
        $this->get('/reports/service-revenue'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('reports/service-revenue')->where('totals.revenue', '500.00'));
        $this->get('/reports/revenue'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/revenue')
                ->where('totals.product', '1000.00')
                ->where('totals.service', '500.00')
                ->where('totals.combined', '1500.00')
                ->where('totals.documents.matches', true));
    }

    public function test_pos_sales_service_parts_returns_and_discounts_reconcile()
    {
        $this->criticalServiceInvoice('150.00');       // 1500 − 150: parts 900.00, labour 450.00
        $this->sale($this->ic, 2, '100.00');            // 1600 − 100 = 1500
        $sale = $this->sale($this->connector, 3);       // 600
        app(CreateSaleReturn::class)->handle($sale, [
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1]], 'reason' => 'Faulty',
        ]);                                             // −200

        $totals = $this->totals();

        $this->assertSame('2100.00', $totals['product']['pos_sales']);
        $this->assertSame('900.00', $totals['product']['service_parts']);
        $this->assertSame('200.00', $totals['product']['returns']);
        $this->assertSame('2800.00', $totals['product']['net']);
        $this->assertSame('450.00', $totals['service']['revenue']);
        $this->assertSame('3250.00', $totals['combined']);
        $this->assertSame(['pos' => 5, 'parts' => 2, 'returned' => 1, 'net' => 6], $totals['product']['quantity']);

        // Document totals: sales 2100 + service invoice 1350 − returns 200 = 3250.
        $this->assertSame('3250.00', $totals['documents']['total']);
        $this->assertTrue($totals['documents']['matches']);
        $this->assertSame(['sales' => 2, 'service_invoices' => 1, 'sale_returns' => 1], array_intersect_key($totals['documents'], array_flip(['sales', 'service_invoices', 'sale_returns'])));

        // Cost of goods: IC 2×500 + connector 3×80 (POS) + parts 500 + 80 − returned connector 80.
        $this->assertSame('1740.00', $totals['product']['cost']);
        $this->assertSame('1060.00', $totals['product']['gross_profit']);
    }

    public function test_by_period_splits_product_and_service_per_day()
    {
        $this->criticalServiceInvoice();
        $this->travelTo('2026-09-16 09:00:00');
        $this->sale($this->connector, 1);

        $rows = app(RevenueReport::class)->byPeriod(ReportPeriod::make('2026-09-01', '2026-09-30'));

        $this->assertSame([
            ['period' => '2026-09-15', 'pos_sales' => '0.00', 'service_parts' => '1000.00', 'returns' => '0.00', 'product_quantity' => 2, 'service' => '500.00', 'product' => '1000.00', 'combined' => '1500.00'],
            ['period' => '2026-09-16', 'pos_sales' => '200.00', 'service_parts' => '0.00', 'returns' => '0.00', 'product_quantity' => 1, 'service' => '0.00', 'product' => '200.00', 'combined' => '200.00'],
        ], $rows);

        $monthly = app(RevenueReport::class)->byPeriod(ReportPeriod::make('2026-09-01', '2026-09-30', 'month'));
        $this->assertCount(1, $monthly);
        $this->assertSame(['2026-09', '1200.00', '500.00', '1700.00'], [$monthly[0]['period'], $monthly[0]['product'], $monthly[0]['service'], $monthly[0]['combined']]);
    }

    public function test_by_product_includes_pos_and_parts_net_of_returns()
    {
        $this->criticalServiceInvoice();
        $sale = $this->sale($this->connector, 3);
        app(CreateSaleReturn::class)->handle($sale, ['items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1]], 'reason' => 'x']);

        $rows = collect(app(RevenueReport::class)->byProduct(ReportPeriod::make('2026-09-01', '2026-09-30'))->items())->keyBy('name');

        $this->assertSame(
            ['pos_qty' => 3, 'pos_amount' => '600.00', 'parts_qty' => 1, 'parts_amount' => '200.00', 'returned_qty' => 1, 'returned_amount' => '200.00', 'net_qty' => 3, 'net_amount' => '600.00'],
            array_intersect_key($rows['Connector'], array_flip(['pos_qty', 'pos_amount', 'parts_qty', 'parts_amount', 'returned_qty', 'returned_amount', 'net_qty', 'net_amount'])),
        );
        $this->assertSame('800.00', $rows['IC']['net_amount']);
    }

    public function test_service_revenue_by_technician_and_line_detail()
    {
        $this->criticalServiceInvoice();

        $technicians = app(RevenueReport::class)->serviceByTechnician(ReportPeriod::make('2026-09-01', '2026-09-30'));
        $this->assertSame([['technician_id' => null, 'technician' => null, 'invoices' => 1, 'revenue' => '500.00']], $technicians);

        $lines = app(RevenueReport::class)->serviceLineDetails(ReportPeriod::make('2026-09-01', '2026-09-30'));
        $this->assertSame(1, $lines->total()); // SERVICE lines only — the two parts are not listed
        $this->assertSame('Repair', $lines->items()[0]['description']);
        $this->assertSame('500.00', $lines->items()[0]['amount']);
    }

    public function test_date_range_excludes_other_periods()
    {
        $this->criticalServiceInvoice();

        $this->assertSame('0.00', $this->totals('2026-09-16', '2026-09-30')['combined']);
        $this->assertSame('0.00', $this->totals('2026-08-01', '2026-09-14')['combined']);
        $this->assertSame('1500.00', $this->totals('2026-09-15', '2026-09-15')['combined']);
    }

    public function test_days_are_grouped_in_the_report_time_zone()
    {
        // 2026-09-15 20:30 UTC is 2026-09-16 02:30 in Dhaka (UTC+6).
        $this->travelTo('2026-09-15 20:30:00');
        $this->criticalServiceInvoice();

        config(['reports.timezone' => 'Asia/Dhaka']);
        $this->assertSame('0.00', $this->totals('2026-09-15', '2026-09-15')['combined']);
        $this->assertSame('1500.00', $this->totals('2026-09-16', '2026-09-16')['combined']);
        $this->assertSame('2026-09-16', app(RevenueReport::class)->byPeriod(ReportPeriod::make('2026-09-01', '2026-09-30'))[0]['period']);

        config(['reports.timezone' => 'UTC']);
        $this->assertSame('1500.00', $this->totals('2026-09-15', '2026-09-15')['combined']);
    }
}
