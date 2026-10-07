<?php

namespace Tests\Feature\Reports;

use App\Actions\Inventory\AdjustStock;
use App\Enums\AdjustmentReason;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T044 — stock summary and movements, from the stock ledger.
 */
class StockReportTest extends ReportTestCase
{
    public function test_current_stock_value_and_low_stock()
    {
        $this->product('Case', '300.00', stock: 20, reorder: 5);       // value 20 × 10
        $this->product('Cable', '150.00', stock: 3, reorder: 5);       // low
        $this->product('Glass', '99.00', stock: 0, reorder: 2);        // out and low

        $this->get('/reports/stock')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/stock')
                ->where('totals', ['products' => 3, 'units' => 23, 'value' => '230.00', 'low' => 2, 'out' => 1])
                ->where('products.total', 3)
                ->where('products.data.0.name', 'Cable')
                ->where('products.data.0.quantity', 3)
                ->where('products.data.0.is_low', true)
                ->where('products.data.0.value', '30.00'));

        $this->get('/reports/stock?status=low')->assertInertia(fn (Assert $page) => $page->where('products.total', 2));
        $this->get('/reports/stock?status=out')->assertInertia(fn (Assert $page) => $page->where('products.total', 1)->where('products.data.0.name', 'Glass'));
        $this->get('/reports/stock?q=cas')->assertInertia(fn (Assert $page) => $page->where('products.total', 1));
    }

    public function test_movement_summary_has_opening_in_out_and_closing()
    {
        $this->travelTo('2026-08-20 10:00:00');
        $case = $this->product('Case', '300.00', stock: 10);           // opening stock in August

        $this->travelTo('2026-09-10 10:00:00');
        app(AdjustStock::class)->handle($case->id, 'in', 5, AdjustmentReason::Found);
        $this->sale([[$case, 4]]);
        $this->serviceInvoice([[$case, 1]], '100');

        $this->travelTo('2026-10-01 10:00:00');
        $this->sale([[$case, 2]]);                                      // after the period

        $this->get('/reports/stock?from=2026-09-01&to=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('movements.total', 1)
                ->where('movements.data.0', ['product_id' => $case->id, 'name' => 'Case', 'sku' => $case->sku, 'opening' => 10, 'in' => 5, 'out' => 5, 'closing' => 10])
                ->where('movementTypes', [
                    ['type' => 'ADJUSTMENT_IN', 'label' => 'Adjustment (in)', 'movements' => 1, 'quantity' => 5],
                    ['type' => 'SALE_OUT', 'label' => 'Sale', 'movements' => 1, 'quantity' => -4],
                    ['type' => 'SERVICE_PART_OUT', 'label' => 'Service part', 'movements' => 1, 'quantity' => -1],
                ])
                // Current stock is today's balance.
                ->where('products.data.0.quantity', 8));
    }

    public function test_cost_is_hidden_without_purchase_access()
    {
        $this->product('Case', '300.00');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.view', 'inventory.view');

        $this->actingAs($viewer)->get('/reports/stock')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('showCost', false)->missing('totals.value')->missing('products.data.0.value')->missing('products.data.0.unit_cost'));
    }
}
