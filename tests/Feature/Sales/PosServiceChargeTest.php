<?php

namespace Tests\Feature\Sales;

use App\Domain\Inventory\StockService;
use App\Domain\Reporting\ReportPeriod;
use App\Domain\Reporting\RevenueReport;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleServiceCharge;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Service / labour charges billed on a POS sale together with products.
 */
#[Group('critical')]
class PosServiceChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Party $customer;

    private Product $guard;

    protected function setUp(): void
    {
        parent::setUp();

        config(['reports.timezone' => 'UTC']);
        $this->travelTo('2026-09-15 10:00:00');
        $this->cashier = $this->generalUser();
        $this->customer = Party::factory()->customer()->create();
        $this->guard = Product::factory()->withStock(10)->create(['name' => 'Tempered glass', 'retail_price' => '300.00', 'purchase_price' => '120.00']);
    }

    private function sell(array $items, array $services, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->cashier)->post('/sales', array_merge([
            'sale_type' => 'RETAIL',
            'party_id' => null,
            'items' => $items,
            'services' => $services,
            'discount' => '0',
            'paid_amount' => '0',
            'payment_method' => 'CASH',
        ], $overrides));
    }

    public function test_service_charge_is_added_to_the_invoice_total_without_stock()
    {
        $this->sell(
            [['product_id' => $this->guard->id, 'quantity' => 2]],
            [['description' => 'Screen guard fitting', 'amount' => '100']],
            ['paid_amount' => '700.00'],
        )->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('700.00', $sale->subtotal);   // 600 products + 100 service
        $this->assertSame('700.00', $sale->total);
        $this->assertSame('100.00', $sale->service_total);
        $this->assertSame('0.00', $sale->due_amount);
        $this->assertSame('240.00', $sale->cost_total);  // products only

        $charge = SaleServiceCharge::sole();
        $this->assertSame(['Screen guard fitting', '100.00', '0.00', '100.00'], [$charge->description, $charge->amount, $charge->discount_share, $charge->line_total]);

        $this->assertSame(1, StockMovement::where('type', 'SALE_OUT')->count());
        $this->assertSame(8, app(StockService::class)->balance($this->guard));
    }

    public function test_invoice_discount_is_shared_by_product_and_service_lines()
    {
        $this->sell(
            [['product_id' => $this->guard->id, 'quantity' => 1]],
            [['description' => 'Software update', 'amount' => '100']],
            ['discount' => '40', 'party_id' => $this->customer->id],
        )->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('360.00', $sale->total);
        $this->assertSame('30.00', $sale->items()->sole()->discount_share);   // 300 / 400 of 40
        $this->assertSame('10.00', SaleServiceCharge::sole()->discount_share); // 100 / 400 of 40
        $this->assertSame('90.00', $sale->service_total);

        // The whole invoice is the customer's receivable.
        $this->assertSame('360.00', $this->customer->fresh()->balance);
    }

    public function test_a_service_only_sale_is_allowed()
    {
        $this->sell([], [['description' => 'Phone setup', 'amount' => '250']], ['paid_amount' => '250'])->assertSessionHasNoErrors();

        $this->assertSame('250.00', Sale::sole()->total);
        $this->assertSame(0, StockMovement::where('type', 'SALE_OUT')->count());
    }

    public function test_validation()
    {
        $this->sell([], [])->assertSessionHasErrors('items');
        $this->sell([], [['description' => '', 'amount' => '0']])->assertSessionHasErrors(['services.0.description', 'services.0.amount']);
        $this->sell([['product_id' => $this->guard->id, 'quantity' => 1]], [['description' => 'Fitting', 'amount' => '100']], ['discount' => '401'])
            ->assertSessionHasErrors('discount');

        $this->assertSame(0, Sale::count());
    }

    public function test_receipt_and_detail_show_the_service_lines()
    {
        $this->sell([['product_id' => $this->guard->id, 'quantity' => 1]], [['description' => 'Screen guard fitting', 'amount' => '100']], ['paid_amount' => '400']);
        $sale = Sale::sole();

        foreach (["/sales/{$sale->id}/receipt", "/sales/{$sale->id}"] as $url) {
            $this->actingAs($this->cashier)->get($url)->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('sale.data.total', '400.00')
                    ->where('sale.data.service_total', '100.00')
                    ->where('sale.data.service_charges.0.description', 'Screen guard fitting')
                    ->where('sale.data.service_charges.0.amount', '100.00')
                    ->has('sale.data.items', 1));
        }
    }

    public function test_service_charge_is_service_revenue_not_product_revenue()
    {
        $this->sell(
            [['product_id' => $this->guard->id, 'quantity' => 2]],
            [['description' => 'Screen guard fitting', 'amount' => '100']],
            ['paid_amount' => '700.00'],
        );
        $period = ReportPeriod::make('2026-09-01', '2026-09-30');

        $totals = app(RevenueReport::class)->totals($period);
        $this->assertSame('600.00', $totals['product']['pos_sales']);
        $this->assertSame('600.00', $totals['product']['net']);
        $this->assertSame('360.00', $totals['product']['gross_profit']);
        $this->assertSame('100.00', $totals['service']['revenue']);
        $this->assertSame('100.00', $totals['service']['pos_charges']);
        $this->assertSame('0.00', $totals['service']['repairs']);
        $this->assertSame(1, $totals['service']['pos_sales']);
        $this->assertSame('700.00', $totals['combined']);
        $this->assertTrue($totals['documents']['matches']);

        $byPeriod = app(RevenueReport::class)->byPeriod($period);
        $this->assertSame(['600.00', '100.00', '700.00'], [$byPeriod[0]['product'], $byPeriod[0]['service'], $byPeriod[0]['combined']]);

        $this->assertSame(
            [['source' => 'POS', 'technician_id' => null, 'technician' => 'Counter sales (POS)', 'invoices' => 1, 'revenue' => '100.00']],
            app(RevenueReport::class)->serviceByTechnician($period),
        );

        $lines = app(RevenueReport::class)->serviceLineDetails($period);
        $this->assertSame(1, $lines->total());
        $this->assertSame(['POS', 'Screen guard fitting', '100.00', Sale::sole()->invoice_no, null, 'Walk-in customer'], [
            $lines->items()[0]['source'], $lines->items()[0]['description'], $lines->items()[0]['amount'],
            $lines->items()[0]['invoice_no'], $lines->items()[0]['job_id'], $lines->items()[0]['customer'],
        ]);

        $this->actingAs($this->admin())->get('/reports/service-revenue?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('totals.revenue', '100.00')->where('lines.data.0.source', 'POS'));
    }

    public function test_returning_the_product_leaves_the_service_charge_billed()
    {
        $this->sell(
            [['product_id' => $this->guard->id, 'quantity' => 1]],
            [['description' => 'Fitting', 'amount' => '100']],
            ['party_id' => $this->customer->id],
        );
        $sale = Sale::sole();

        $this->actingAs($this->admin())->post("/sales/{$sale->id}/returns", [
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1]], 'reason' => 'Cracked',
        ])->assertSessionHasNoErrors();

        $this->assertSame('100.00', $sale->fresh()->due_amount);
        $this->assertSame('100.00', $this->customer->fresh()->balance);
        $this->assertSame(10, app(StockService::class)->balance($this->guard));
    }
}
