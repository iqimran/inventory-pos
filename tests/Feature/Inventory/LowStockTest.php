<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\LowStockQuery;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LowStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_products_at_or_below_reorder_level()
    {
        Product::factory()->withStock(2)->create(['name' => 'Below', 'reorder_level' => 5]);
        Product::factory()->withStock(5)->create(['name' => 'At', 'reorder_level' => 5]);
        Product::factory()->withStock(6)->create(['name' => 'Above', 'reorder_level' => 5]);
        Product::factory()->create(['name' => 'Out', 'reorder_level' => 5]);
        Product::factory()->create(['name' => 'Untracked', 'reorder_level' => 0]);
        Product::factory()->inactive()->create(['name' => 'Inactive', 'reorder_level' => 5]);

        $names = app(LowStockQuery::class)->query()->pluck('name')->all();

        // Out of stock first, then by largest shortfall.
        $this->assertSame(['Out', 'Below', 'At'], $names);
        $this->assertSame(3, app(LowStockQuery::class)->count());
    }

    public function test_low_stock_reflects_stock_changes()
    {
        $product = Product::factory()->withStock(10)->create(['reorder_level' => 5]);
        $this->assertSame(0, app(LowStockQuery::class)->count());

        $this->actingAs($this->admin())->post('/inventory/adjustments', [
            'product_id' => $product->id, 'direction' => 'out', 'quantity' => 6, 'reason' => 'DAMAGED',
        ]);

        $this->assertSame(1, app(LowStockQuery::class)->count());
    }

    public function test_low_stock_page_shows_shortfall_and_filters()
    {
        $category = Category::factory()->create();
        Product::factory()->withStock(1)->create(['name' => 'Phone case', 'reorder_level' => 4, 'category_id' => $category->id]);
        Product::factory()->create(['name' => 'Screen guard', 'reorder_level' => 3]);

        $this->actingAs($this->generalUser())->get('/inventory/low-stock')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inventory/low-stock')
                ->has('products.data', 2)
                ->where('products.data.0.name', 'Screen guard')
                ->where('products.data.0.stock', 0)
                ->where('products.data.1.shortfall', 3));

        $this->actingAs($this->generalUser())->get("/inventory/low-stock?category_id={$category->id}")
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.name', 'Phone case'));

        $this->actingAs($this->generalUser())->get('/inventory/low-stock?only_out_of_stock=1')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.name', 'Screen guard'));
    }

    public function test_low_stock_requires_inventory_view_permission()
    {
        $this->actingAs(User::factory()->create())->get('/inventory/low-stock')->assertForbidden();
    }
}
