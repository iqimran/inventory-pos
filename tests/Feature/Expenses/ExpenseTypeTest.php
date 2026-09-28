<?php

namespace Tests\Feature\Expenses;

use App\Enums\Permission;
use App\Models\ExpenseType;
use App\Models\User;
use Database\Seeders\ExpenseTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T032 — expense type master data.
 */
class ExpenseTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo(Permission::ExpensesView->value, Permission::ExpensesManage->value);
    }

    public function test_default_types_are_seeded_idempotently()
    {
        $this->seed(ExpenseTypeSeeder::class);
        $this->seed(ExpenseTypeSeeder::class);

        $this->assertSame(
            ['Electricity', 'Internet', 'Miscellaneous', 'Rent', 'Salary', 'Tools/maintenance', 'Transport'],
            ExpenseType::orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_create_update_and_list()
    {
        $this->actingAs($this->manager)->post('/expense-types', ['name' => ' Rent ', 'description' => 'Shop rent', 'is_active' => true])
            ->assertSessionHasNoErrors();

        $type = ExpenseType::sole();
        $this->assertSame('Rent', $type->name);
        $this->assertSame($this->manager->id, $type->created_by);

        $this->actingAs($this->manager)->put("/expense-types/{$type->id}", ['name' => 'Shop rent', 'description' => '', 'is_active' => false])
            ->assertSessionHasNoErrors();
        $type->refresh();
        $this->assertSame('Shop rent', $type->name);
        $this->assertNull($type->description);
        $this->assertFalse($type->is_active);

        $this->actingAs($this->manager)->get('/expense-types?search=shop')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('expenses/types')->has('records.data', 1)->where('records.data.0.expenses_count', 0));
    }

    public function test_validation()
    {
        ExpenseType::factory()->create(['name' => 'Rent']);

        $this->actingAs($this->manager)->post('/expense-types', ['name' => '', 'is_active' => true])->assertSessionHasErrors('name');
        $this->actingAs($this->manager)->post('/expense-types', ['name' => 'Rent', 'is_active' => true])->assertSessionHasErrors('name');
        $this->actingAs($this->manager)->post('/expense-types', ['name' => 'X', 'is_active' => 'maybe'])->assertSessionHasErrors('is_active');

        $this->assertSame(1, ExpenseType::count());
    }

    public function test_only_unused_types_can_be_deleted()
    {
        $unused = ExpenseType::factory()->create();
        $used = ExpenseType::factory()->create();
        $this->actingAs($this->manager)->post('/expenses', [
            'expense_type_id' => $used->id, 'amount' => '100', 'expense_date' => today()->toDateString(), 'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->delete("/expense-types/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($unused);

        $this->actingAs($this->manager)->delete("/expense-types/{$used->id}")->assertSessionHasErrors('record');
        $this->assertModelExists($used);
    }

    public function test_authorization()
    {
        $type = ExpenseType::factory()->create();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ExpensesView->value);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get('/expense-types')->assertForbidden();
        $this->actingAs($viewer)->get('/expense-types')->assertOk();
        $this->actingAs($viewer)->post('/expense-types', ['name' => 'New', 'is_active' => true])->assertForbidden();
        $this->actingAs($viewer)->put("/expense-types/{$type->id}", ['name' => 'New', 'is_active' => true])->assertForbidden();
        $this->actingAs($viewer)->delete("/expense-types/{$type->id}")->assertForbidden();

        $this->assertSame(1, ExpenseType::count());
    }
}
