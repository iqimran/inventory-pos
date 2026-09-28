<?php

namespace Tests\Feature\Expenses;

use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Enums\Permission;
use App\Models\ExpenseType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T034 — expense reporting by date and expense type.
 */
class ExpenseReportTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private ExpenseType $rent;

    private ExpenseType $electricity;

    private ExpenseType $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-28 10:00:00');
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo(Permission::ExpensesView->value);
        $this->actingAs($this->admin());

        $this->rent = ExpenseType::factory()->create(['name' => 'Rent']);
        $this->electricity = ExpenseType::factory()->create(['name' => 'Electricity']);
        $this->transport = ExpenseType::factory()->create(['name' => 'Transport']);

        $this->expense($this->rent, '15000.00', '2026-09-01');
        $this->expense($this->electricity, '2300.40', '2026-09-01');
        $this->expense($this->transport, '150.25', '2026-09-15');
        $this->expense($this->transport, '99.75', '2026-09-28');                            // last day of the range
        $this->expense($this->electricity, '2100.00', '2026-08-31');                         // before the range
        $void = $this->expense($this->rent, '999.00', '2026-09-10');
        app(VoidExpense::class)->handle($void, 'Duplicate');                                // never counted
    }

    private function expense(ExpenseType $type, string $amount, string $date)
    {
        return app(SaveExpense::class)->handle(null, [
            'expense_type_id' => $type->id, 'amount' => $amount, 'expense_date' => $date, 'payment_method' => 'CASH',
        ]);
    }

    public function test_totals_by_type_and_by_day_exclude_void_and_out_of_range()
    {
        $this->actingAs($this->viewer)->get('/expenses/report?from=2026-09-01&to=2026-09-28')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('expenses/report')
                ->where('report.from', '2026-09-01')
                ->where('report.to', '2026-09-28')
                ->where('report.total', '17550.40')
                ->where('report.count', 4)
                ->has('report.by_type', 3)
                ->where('report.by_type.0.name', 'Rent')
                ->where('report.by_type.0.total', '15000.00')
                ->where('report.by_type.0.count', 1)
                ->where('report.by_type.0.share', '85.47')
                ->where('report.by_type.1.name', 'Electricity')
                ->where('report.by_type.1.total', '2300.40')
                ->where('report.by_type.2.name', 'Transport')
                ->where('report.by_type.2.total', '250.00')
                ->where('report.by_type.2.count', 2)
                ->has('report.by_period', 3)
                ->where('report.by_period.0', ['period' => '2026-09-01', 'count' => 2, 'total' => '17300.40'])
                ->where('report.by_period.1', ['period' => '2026-09-15', 'count' => 1, 'total' => '150.25'])
                ->where('report.by_period.2', ['period' => '2026-09-28', 'count' => 1, 'total' => '99.75']));
    }

    public function test_filter_by_type()
    {
        $this->actingAs($this->viewer)->get("/expenses/report?from=2026-08-01&to=2026-09-28&expense_type_id={$this->electricity->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.total', '4400.40')
                ->where('report.count', 2)
                ->has('report.by_type', 1)
                ->where('report.by_type.0.share', '100.00')
                ->has('report.by_period', 2));
    }

    public function test_group_by_month()
    {
        $this->actingAs($this->viewer)->get('/expenses/report?from=2026-08-01&to=2026-09-28&group_by=month')
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.group_by', 'month')
                ->where('report.total', '19650.40')
                ->where('report.by_period', [
                    ['period' => '2026-08', 'count' => 1, 'total' => '2100.00'],
                    ['period' => '2026-09', 'count' => 4, 'total' => '17550.40'],
                ]));
    }

    public function test_defaults_to_the_current_month_to_date()
    {
        $this->actingAs($this->viewer)->get('/expenses/report')
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.from', '2026-09-01')
                ->where('report.to', '2026-09-28')
                ->where('report.total', '17550.40')
                ->has('types', 3));
    }

    public function test_empty_period()
    {
        $this->actingAs($this->viewer)->get('/expenses/report?from=2025-01-01&to=2025-01-31')
            ->assertInertia(fn (Assert $page) => $page->where('report.total', '0.00')->where('report.count', 0)->has('report.by_type', 0)->has('report.by_period', 0));
    }

    public function test_validation_and_authorization()
    {
        $this->actingAs($this->viewer)->get('/expenses/report?from=2026-09-10&to=2026-09-01')->assertSessionHasErrors('to');
        $this->actingAs($this->viewer)->get('/expenses/report?group_by=year')->assertSessionHasErrors('group_by');
        $this->actingAs(User::factory()->create())->get('/expenses/report')->assertForbidden();
    }
}
