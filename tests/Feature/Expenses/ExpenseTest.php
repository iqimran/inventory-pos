<?php

namespace Tests\Feature\Expenses;

use App\Enums\ExpenseAuditAction;
use App\Enums\ExpenseStatus;
use App\Enums\Permission;
use App\Models\Expense;
use App\Models\ExpenseAudit;
use App\Models\ExpenseType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

/**
 * T033 — expense entry, edit (audited), void (no hard delete) and authorization.
 */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private ExpenseType $rent;

    private ExpenseType $electricity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo(Permission::ExpensesView->value, Permission::ExpensesManage->value, Permission::ExpensesVoid->value);
        $this->rent = ExpenseType::factory()->create(['name' => 'Rent']);
        $this->electricity = ExpenseType::factory()->create(['name' => 'Electricity']);
    }

    private function record(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->post('/expenses', array_merge([
            'expense_type_id' => $this->rent->id,
            'amount' => '15000.00',
            'expense_date' => today()->toDateString(),
            'payment_method' => 'BANK',
            'reference' => 'CHQ-1001',
            'notes' => 'September rent',
        ], $overrides));
    }

    public function test_record_an_expense()
    {
        $this->record()->assertSessionHasNoErrors();

        $expense = Expense::sole();
        $this->assertMatchesRegularExpression('/^EXP-\d{6}-000001$/', $expense->expense_no);
        $this->assertSame($this->rent->id, $expense->expense_type_id);
        $this->assertSame('15000.00', $expense->amount);
        $this->assertSame(today()->toDateString(), $expense->expense_date->toDateString());
        $this->assertSame('BANK', $expense->payment_method->value);
        $this->assertSame('CHQ-1001', $expense->reference);
        $this->assertSame('September rent', $expense->notes);
        $this->assertSame(ExpenseStatus::Recorded, $expense->status);
        $this->assertSame($this->manager->id, $expense->created_by);

        $audit = ExpenseAudit::sole();
        $this->assertSame(ExpenseAuditAction::Created, $audit->action);
        $this->assertSame($this->manager->id, $audit->created_by);
    }

    public function test_back_dated_expenses_are_numbered_by_their_month()
    {
        $this->record(['expense_date' => '2026-01-15'])->assertSessionHasNoErrors();

        $this->assertSame('EXP-202601-000001', Expense::sole()->expense_no);
    }

    public function test_redirects_to_the_expense()
    {
        $this->record()->assertRedirect('/expenses/'.Expense::sole()->id);
    }

    public function test_validation()
    {
        $this->record(['amount' => '0'])->assertSessionHasErrors('amount');
        $this->record(['amount' => '-5'])->assertSessionHasErrors('amount');
        $this->record(['amount' => '10.123'])->assertSessionHasErrors('amount');
        $this->record(['expense_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('expense_date');
        $this->record(['expense_date' => 'not-a-date'])->assertSessionHasErrors('expense_date');
        $this->record(['payment_method' => 'BITCOIN'])->assertSessionHasErrors('payment_method');
        $this->record(['expense_type_id' => 999999])->assertSessionHasErrors('expense_type_id');
        $this->record(['expense_type_id' => ExpenseType::factory()->inactive()->create()->id])->assertSessionHasErrors('expense_type_id');
        $this->record(['reference' => str_repeat('x', 101)])->assertSessionHasErrors('reference');

        $this->assertSame(0, Expense::count());
    }

    public function test_edit_is_audited_with_before_and_after_values()
    {
        $this->record();
        $expense = Expense::sole();

        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_type_id' => $this->electricity->id,
            'amount' => '14500.50',
            'expense_date' => $expense->expense_date->toDateString(),
            'payment_method' => 'CASH',
            'reference' => '',
            'notes' => 'September rent',
        ])->assertSessionHasNoErrors()->assertRedirect("/expenses/{$expense->id}");

        $expense->refresh();
        $this->assertSame('14500.50', $expense->amount);
        $this->assertSame($this->electricity->id, $expense->expense_type_id);
        $this->assertNull($expense->reference);

        $audit = ExpenseAudit::where('action', ExpenseAuditAction::Updated)->sole();
        // assertEquals: MySQL's JSON type does not preserve key order.
        $this->assertEquals([
            'expense_type_id' => ['from' => (string) $this->rent->id, 'to' => (string) $this->electricity->id],
            'amount' => ['from' => '15000.00', 'to' => '14500.50'],
            'payment_method' => ['from' => 'BANK', 'to' => 'CASH'],
            'reference' => ['from' => 'CHQ-1001', 'to' => null],
        ], $audit->changes);
    }

    public function test_saving_without_changes_writes_no_audit()
    {
        $this->record();
        $expense = Expense::sole();

        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_type_id' => $this->rent->id, 'amount' => '15000', 'expense_date' => $expense->expense_date->toDateString(),
            'payment_method' => 'BANK', 'reference' => 'CHQ-1001', 'notes' => 'September rent',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ExpenseAudit::count());
    }

    public function test_an_expense_keeps_its_since_deactivated_type_when_edited()
    {
        $this->record();
        $expense = Expense::sole();
        $this->rent->update(['is_active' => false]);

        $this->actingAs($this->manager)->put("/expenses/{$expense->id}", [
            'expense_type_id' => $this->rent->id, 'amount' => '16000', 'expense_date' => $expense->expense_date->toDateString(), 'payment_method' => 'BANK',
        ])->assertSessionHasNoErrors();

        $this->assertSame('16000.00', $expense->fresh()->amount);
    }

    public function test_void_keeps_the_record_and_is_final()
    {
        $this->record();
        $expense = Expense::sole();

        $this->actingAs($this->manager)->post("/expenses/{$expense->id}/void", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post("/expenses/{$expense->id}/void", ['reason' => 'Entered twice'])->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Void, $expense->status);
        $this->assertSame('Entered twice', $expense->void_reason);
        $this->assertSame($this->manager->id, $expense->voided_by);
        $this->assertNotNull($expense->voided_at);
        $this->assertSame('15000.00', $expense->amount); // the record itself is untouched

        $audit = ExpenseAudit::where('action', ExpenseAuditAction::Voided)->sole();
        $this->assertSame('Entered twice', $audit->reason);

        // Voided expenses cannot be edited or voided again — not even by an Admin.
        $admin = $this->admin();
        $this->actingAs($admin)->put("/expenses/{$expense->id}", [
            'expense_type_id' => $this->rent->id, 'amount' => '1', 'expense_date' => today()->toDateString(), 'payment_method' => 'CASH',
        ])->assertSessionHasErrors('expense');
        $this->actingAs($admin)->post("/expenses/{$expense->id}/void", ['reason' => 'Again'])->assertSessionHasErrors('expense');
        $this->actingAs($this->manager)->get("/expenses/{$expense->id}/edit")->assertForbidden();

        $this->assertSame('15000.00', $expense->fresh()->amount);
        $this->assertSame(1, ExpenseAudit::where('action', ExpenseAuditAction::Voided)->count());
    }

    public function test_expenses_are_never_hard_deleted()
    {
        $this->record();
        $expense = Expense::sole();

        $this->actingAs($this->admin())->delete("/expenses/{$expense->id}")->assertStatus(405);
        $this->assertModelExists($expense);

        $this->expectException(LogicException::class);
        $expense->delete();
    }

    public function test_audit_entries_are_immutable()
    {
        $this->record();

        $this->expectException(LogicException::class);
        ExpenseAudit::sole()->update(['reason' => 'tampered']);
    }

    public function test_index_filters_and_totals_exclude_void()
    {
        $this->record(['amount' => '1000', 'reference' => 'A']);
        $this->record(['amount' => '250.50', 'expense_type_id' => $this->electricity->id, 'reference' => 'B']);
        $this->record(['amount' => '99', 'reference' => 'C']);
        $voided = Expense::where('reference', 'C')->sole();
        $this->actingAs($this->manager)->post("/expenses/{$voided->id}/void", ['reason' => 'Mistake']);

        $this->actingAs($this->manager)->get('/expenses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('expenses/index')->has('expenses.data', 3)->where('filteredTotal', '1250.50'));

        $this->actingAs($this->manager)->get("/expenses?expense_type_id={$this->electricity->id}")
            ->assertInertia(fn (Assert $page) => $page->has('expenses.data', 1)->where('filteredTotal', '250.50'));

        $this->actingAs($this->manager)->get('/expenses?status=VOID')
            ->assertInertia(fn (Assert $page) => $page->has('expenses.data', 1)->where('filteredTotal', '0.00'));

        // Reference search is exact but case-insensitive.
        $this->actingAs($this->manager)->get('/expenses?q=b')
            ->assertInertia(fn (Assert $page) => $page->has('expenses.data', 1)->where('expenses.data.0.reference', 'B'));
        $this->actingAs($this->manager)->get('/expenses?q=Z')
            ->assertInertia(fn (Assert $page) => $page->has('expenses.data', 0));
        $this->actingAs($this->manager)->get('/expenses?q='.strtolower(Expense::where('reference', 'A')->value('expense_no')))
            ->assertInertia(fn (Assert $page) => $page->has('expenses.data', 1));
    }

    public function test_show_and_form_pages()
    {
        $this->record();
        $expense = Expense::sole();

        $this->actingAs($this->manager)->get("/expenses/{$expense->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('expenses/show')
                ->where('expense.data.expense_no', $expense->expense_no)
                ->where('expense.data.type.name', 'Rent')
                ->where('expense.data.can.update', true)
                ->where('expense.data.can.void', true)
                ->has('expense.data.audits', 1));

        $this->actingAs($this->manager)->get('/expenses/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('expenses/form')->where('expense', null)->has('types', 2)->has('methods', 5));

        $this->actingAs($this->manager)->get("/expenses/{$expense->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->component('expenses/form')->where('expense.data.id', $expense->id));
    }

    public function test_authorization()
    {
        $this->record();
        $expense = Expense::sole();

        $outsider = User::factory()->create();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ExpensesView->value);
        $clerk = User::factory()->create();
        $clerk->givePermissionTo(Permission::ExpensesView->value, Permission::ExpensesManage->value);

        $this->actingAs($outsider)->get('/expenses')->assertForbidden();
        $this->actingAs($outsider)->get("/expenses/{$expense->id}")->assertForbidden();

        $this->actingAs($viewer)->get('/expenses')->assertOk();
        $this->actingAs($viewer)->get("/expenses/{$expense->id}")
            ->assertInertia(fn (Assert $page) => $page->where('expense.data.can.update', false)->where('expense.data.can.void', false));
        $this->record([], $viewer)->assertForbidden();
        $this->actingAs($viewer)->put("/expenses/{$expense->id}", ['amount' => '1'])->assertForbidden();

        // Managing does not include voiding.
        $this->record([], $clerk)->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post("/expenses/{$expense->id}/void", ['reason' => 'x'])->assertForbidden();

        $this->assertSame(ExpenseStatus::Recorded, $expense->fresh()->status);
    }
}
