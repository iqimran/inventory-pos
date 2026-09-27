<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\StockService;
use App\Enums\Permission;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_stock_with_a_reason()
    {
        $admin = $this->admin();
        $product = Product::factory()->create();

        $this->actingAs($admin)->post('/inventory/adjustments', [
            'product_id' => $product->id,
            'direction' => 'in',
            'quantity' => 12,
            'reason' => 'OPENING_STOCK',
            'notes' => 'Initial count',
        ])->assertRedirect("/products/{$product->id}")->assertSessionHas('success');

        $movement = StockMovement::sole();
        $this->assertSame('ADJUSTMENT_IN', $movement->type->value);
        $this->assertSame(12, $movement->quantity);
        $this->assertSame(12, $movement->balance_after);
        $this->assertSame('OPENING_STOCK', $movement->reason->value);
        $this->assertSame('Initial count', $movement->notes);
        $this->assertSame($admin->id, $movement->created_by);
        $this->assertNotNull($movement->occurred_at);
        $this->assertSame(12, app(StockService::class)->balance($product));
    }

    public function test_admin_can_remove_stock()
    {
        $product = Product::factory()->withStock(10)->create();

        $this->actingAs($this->admin())->post('/inventory/adjustments', [
            'product_id' => $product->id,
            'direction' => 'out',
            'quantity' => 3,
            'reason' => 'DAMAGED',
        ])->assertSessionHasNoErrors();

        $this->assertSame(-3, StockMovement::latest('id')->first()->quantity);
        $this->assertSame(7, app(StockService::class)->balance($product));
    }

    public function test_adjustment_cannot_make_stock_negative()
    {
        $product = Product::factory()->withStock(2)->create();

        $this->actingAs($this->admin())->post('/inventory/adjustments', [
            'product_id' => $product->id,
            'direction' => 'out',
            'quantity' => 5,
            'reason' => 'LOST',
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(2, app(StockService::class)->balance($product));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_reason_must_match_the_direction()
    {
        $product = Product::factory()->withStock(5)->create();

        $this->actingAs($this->admin())->post('/inventory/adjustments', [
            'product_id' => $product->id, 'direction' => 'out', 'quantity' => 1, 'reason' => 'OPENING_STOCK',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($this->admin())->post('/inventory/adjustments', [
            'product_id' => $product->id, 'direction' => 'in', 'quantity' => 1, 'reason' => 'DAMAGED',
        ])->assertSessionHasErrors('reason');
    }

    public function test_adjustment_input_is_validated()
    {
        $this->actingAs($this->admin())->post('/inventory/adjustments', [
            'product_id' => 999,
            'direction' => 'sideways',
            'quantity' => 0,
            'reason' => 'MADE_UP',
        ])->assertSessionHasErrors(['product_id', 'direction', 'quantity', 'reason']);

        $product = Product::factory()->create();
        $this->actingAs($this->admin())->post('/inventory/adjustments', [
            'product_id' => $product->id, 'direction' => 'in', 'quantity' => 1.5, 'reason' => 'FOUND',
        ])->assertSessionHasErrors('quantity');
    }

    public function test_other_reason_requires_notes()
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post('/inventory/adjustments', [
            'product_id' => $product->id, 'direction' => 'in', 'quantity' => 1, 'reason' => 'OTHER', 'notes' => '  ',
        ])->assertSessionHasErrors('notes');
    }

    public function test_general_user_cannot_adjust_stock_by_default()
    {
        $user = $this->generalUser();
        $product = Product::factory()->create();

        $this->actingAs($user)->get('/inventory/adjustments/create')->assertForbidden();
        $this->actingAs($user)->post('/inventory/adjustments', [
            'product_id' => $product->id, 'direction' => 'in', 'quantity' => 5, 'reason' => 'FOUND',
        ])->assertForbidden();

        $this->assertSame(0, StockMovement::count());
    }

    public function test_granted_permission_allows_adjustment()
    {
        $user = $this->generalUser();
        $user->givePermissionTo(Permission::InventoryAdjust->value);
        $product = Product::factory()->create();

        $this->actingAs($user)->post('/inventory/adjustments', [
            'product_id' => $product->id, 'direction' => 'in', 'quantity' => 5, 'reason' => 'FOUND',
        ])->assertSessionHasNoErrors();

        $this->assertSame($user->id, StockMovement::sole()->created_by);
    }

    public function test_create_page_searches_products_and_preselects()
    {
        $product = Product::factory()->withStock(3)->create(['name' => 'Galaxy Case', 'sku' => 'CASE-1']);

        $this->actingAs($this->admin())->get('/inventory/adjustments/create?q=galaxy')
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/adjustments/create')
                ->where('results.data.0.id', $product->id)
                ->has('reasons', 7));

        $this->actingAs($this->admin())->get("/inventory/adjustments/create?product_id={$product->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selected.data.stock', 3));
    }

    public function test_adjustment_and_movement_lists_render()
    {
        Product::factory()->withStock(3)->create();

        $this->actingAs($this->generalUser())->get('/inventory/adjustments')
            ->assertInertia(fn (Assert $page) => $page->component('inventory/adjustments/index')->has('adjustments.data', 1));

        $this->actingAs($this->generalUser())->get('/inventory/movements?type=ADJUSTMENT_IN')
            ->assertInertia(fn (Assert $page) => $page->component('inventory/movements')->has('movements.data', 1));

        $this->actingAs($this->generalUser())->get('/inventory/movements?type=SALE_OUT')
            ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
    }
}
