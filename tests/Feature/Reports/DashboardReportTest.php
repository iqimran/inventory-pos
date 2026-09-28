<?php

namespace Tests\Feature\Reports;

use App\Actions\Expenses\SaveExpense;
use App\Actions\Purchasing\CreatePurchase;
use App\Models\ExpenseType;
use App\Models\Party;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T039 — dashboard KPIs.
 */
class DashboardReportTest extends ReportTestCase
{
    public function test_kpis_for_the_period()
    {
        $ic = $this->product('IC', '800.00', stock: 10, reorder: 5);
        $connector = $this->product('Connector', '200.00', stock: 4, reorder: 5);   // low stock
        $case = $this->product('Case', '300.00', stock: 10);

        $this->serviceInvoice([[$ic, 1], [$connector, 1]], '500', paid: '1500');   // critical invoice
        $this->sale([[$case, 2]], '600');
        $supplier = Party::factory()->supplier()->create();
        app(CreatePurchase::class)->handle([
            'party_id' => $supplier->id, 'purchase_date' => '2026-09-15', 'items' => [['product_id' => $case->id, 'quantity' => 5, 'unit_cost' => '100.00']],
            'paid_amount' => '200.00', 'payment_method' => 'CASH',
        ]);
        app(SaveExpense::class)->handle(null, ['expense_type_id' => ExpenseType::factory()->create()->id, 'amount' => '75.50', 'expense_date' => '2026-09-15', 'payment_method' => 'CASH']);

        $this->get('/dashboard?from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('summary.product_sales.amount', '1600.00')     // 1000 parts + 600 POS
                ->where('summary.product_sales.quantity', 4)
                ->where('summary.service_revenue', '500.00')
                ->where('summary.combined_revenue', '2100.00')
                ->where('summary.documents.matches', true)
                ->where('summary.purchases.amount', '500.00')
                ->where('summary.purchases.documents', 1)
                ->where('summary.expenses.amount', '75.50')
                ->where('summary.outstanding.payable', '300.00')
                ->where('summary.outstanding.receivable', '0.00')
                ->where('summary.low_stock.count', 1)
                ->where('summary.low_stock.products.0.name', 'Connector')
                ->where('summary.low_stock.products.0.stock', 3)
                ->has('summary.trend', 1)
                ->where('summary.trend.0.combined', '2100.00')
                ->whereNot('summary.product_sales.gross_profit', null));
    }

    public function test_defaults_to_today_and_switches_trend_to_months_for_long_ranges()
    {
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('filters', ['from' => '2026-09-15', 'to' => '2026-09-15', 'group_by' => 'day']));
        $this->get('/dashboard?from=2026-01-01&to=2026-09-15')->assertInertia(fn (Assert $page) => $page->where('filters.group_by', 'month'));
    }

    public function test_users_without_report_access_see_the_welcome_page()
    {
        $this->actingAs($this->generalUser())->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('summary', null));

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.view');
        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->whereNot('summary', null)->where('summary.product_sales.gross_profit', null)); // no cost access
    }
}
