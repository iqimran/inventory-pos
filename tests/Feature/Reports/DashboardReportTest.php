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
                ->where('summary.outstanding.supplier_payable', '300.00')
                ->where('summary.outstanding.customer_receivable', '0.00')
                ->where('summary.low_stock.count', 1)
                ->where('summary.low_stock.products.0.name', 'Connector')
                ->where('summary.low_stock.products.0.stock', 3)
                ->has('summary.trend', 1)
                ->where('summary.trend.0.combined', '2100.00')
                ->whereNot('summary.gross_profit', null));
    }

    public function test_defaults_to_today_and_switches_trend_to_months_for_long_ranges()
    {
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('filters', ['from' => '2026-09-15', 'to' => '2026-09-15', 'group_by' => 'day']));
        $this->get('/dashboard?from=2026-01-01&to=2026-09-15')->assertInertia(fn (Assert $page) => $page->where('filters.group_by', 'month'));
    }

    public function test_users_without_any_dashboard_permission_see_the_welcome_page()
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('summary', null));
    }

    public function test_admin_sees_the_whole_shop()
    {
        $case = $this->product('Case', '300.00', stock: 20);
        $this->sale([[$case, 2]], '600');                              // recorded by Admin
        $this->actingAs($cashier = $this->generalUser());
        $this->sale([[$case, 1]], '100');                              // recorded by the cashier, 200 due

        $this->actingAs($this->admin)->get('/dashboard?from=2026-09-15&to=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'all')
                ->where('summary.product_sales.amount', '900.00')
                ->where('summary.combined_revenue', '900.00')
                ->where('summary.outstanding.customer_receivable', '200.00'));
    }

    public function test_general_user_sees_only_the_transactions_they_recorded()
    {
        $this->product('Connector', '200.00', stock: 2, reorder: 5);       // low stock
        $case = $this->product('Case', '300.00', stock: 20);
        $this->sale([[$case, 5]], '0');                                   // Admin: 1,500 due — not the cashier's
        app(SaveExpense::class)->handle(null, ['expense_type_id' => ExpenseType::factory()->create()->id, 'amount' => '75.50', 'expense_date' => '2026-09-15', 'payment_method' => 'CASH']);

        // Default General User: sales + service, no reports.view, no purchases or expenses.
        $cashier = $this->generalUser();
        $this->actingAs($cashier);
        $this->sale([[$case, 2]], '450');                                 // 600, 150 due
        $this->serviceInvoice([], '250', paid: '250');

        $this->get('/dashboard?from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'own')
                ->where('summary.product_sales.amount', '600.00')
                ->where('summary.product_sales.quantity', 2)
                ->where('summary.service_revenue', '250.00')
                ->where('summary.combined_revenue', '850.00')
                ->where('summary.documents.sales', 1)
                ->where('summary.documents.matches', true)
                ->where('summary.trend.0.combined', '850.00')
                ->where('summary.outstanding.customer_receivable', '150.00')
                ->where('summary.outstanding.customer_receivable_parties', 1)
                ->where('summary.outstanding.supplier_payable', '0.00')
                ->where('summary.low_stock.count', 1)
                ->missing('summary.gross_profit')   // purchase costs
                ->missing('summary.purchases')
                ->missing('summary.expenses'));

        // Another general user who recorded nothing sees zeros, not the shop's figures.
        $this->actingAs($this->generalUser())->get('/dashboard?from=2026-09-15&to=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.product_sales.amount', '0.00')
                ->where('summary.combined_revenue', '0.00')
                ->where('summary.outstanding.customer_receivable', '0.00'));
    }

    public function test_own_purchases_expenses_and_gross_profit_follow_module_permissions()
    {
        $case = $this->product('Case', '300.00', stock: 20);
        $supplier = Party::factory()->supplier()->create();
        $purchase = fn () => app(CreatePurchase::class)->handle([
            'party_id' => $supplier->id, 'purchase_date' => '2026-09-15', 'items' => [['product_id' => $case->id, 'quantity' => 5, 'unit_cost' => '100.00']],
            'paid_amount' => '200.00', 'payment_method' => 'CASH',
        ]);
        $purchase();                                                       // Admin's purchase

        $user = User::factory()->create();
        $user->givePermissionTo('reports.view', 'sales.view', 'purchases.view');
        $this->actingAs($user);
        $purchase();                                                       // 500, 300 payable
        $this->sale([[$case, 1]], '300');

        $this->get('/dashboard?from=2026-09-15&to=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.product_sales.amount', '300.00')
                ->has('summary.gross_profit')                              // sales + purchase costs
                ->where('summary.purchases.amount', '500.00')
                ->where('summary.purchases.documents', 1)
                ->where('summary.outstanding.supplier_payable', '300.00')
                ->missing('summary.service_revenue')                       // no service permission
                ->missing('summary.expenses')                              // no expenses permission
                ->missing('summary.low_stock'));                           // no inventory.view

        // reports.view alone shows nothing at all.
        $reportsOnly = User::factory()->create();
        $reportsOnly->givePermissionTo('reports.view');
        $this->actingAs($reportsOnly)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('summary', null));
    }

    public function test_own_expenses_only()
    {
        $type = ExpenseType::factory()->create();
        $expense = fn (string $amount) => app(SaveExpense::class)->handle(null, ['expense_type_id' => $type->id, 'amount' => $amount, 'expense_date' => '2026-09-15', 'payment_method' => 'CASH']);
        $expense('500.00');                                                // Admin's

        $user = User::factory()->create();
        $user->givePermissionTo('expenses.manage');
        $this->actingAs($user);
        $expense('75.50');

        $this->get('/dashboard?from=2026-09-15&to=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.expenses.amount', '75.50')
                ->where('summary.expenses.entries', 1)
                ->missing('summary.product_sales'));
    }
}
