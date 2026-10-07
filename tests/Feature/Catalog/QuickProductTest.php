<?php

namespace Tests\Feature\Catalog;

use App\Enums\Permission;
use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a product from the purchase form without leaving it.
 */
class QuickProductTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'iPhone 15 Back Cover',
            'sku' => 'cov-ip15',
            'barcode' => '',
            'subcategory_id' => '',
            'brand_id' => '',
            'purchase_price' => '150',
            'retail_price' => '350.00',
            'wholesale_price' => '300.00',
            'reorder_level' => 2,
            'is_active' => true,
        ], $overrides);
    }

    public function test_options_list_active_master_data()
    {
        Category::factory()->create(['name' => 'Covers']);
        Category::factory()->create(['name' => 'Old', 'is_active' => false]);
        Unit::factory()->create(['name' => 'Piece']);

        $this->actingAs($this->admin())->getJson('/products/quick/options')
            ->assertOk()
            ->assertJsonPath('data.categories.0.name', 'Covers')
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.units.0.name', 'Piece')
            ->assertJsonStructure(['data' => ['categories', 'subcategories', 'brands', 'units']]);
    }

    public function test_creates_the_product_and_returns_it_for_the_purchase_line()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/products/quick', $this->payload(['generate_barcode' => true]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'iPhone 15 Back Cover')
            ->assertJsonPath('data.sku', 'COV-IP15')
            ->assertJsonPath('data.purchase_price', '150.00')
            ->assertJsonPath('data.unit', Unit::sole()->short_name)
            ->assertJsonPath('data.stock', 0);

        $product = Product::sole();
        $this->assertNotNull($product->barcode);
        $this->assertSame($admin->id, $product->created_by);
        $this->assertSame(0, StockMovement::count()); // stock only arrives through the purchase

        // The new product can be purchased straight away.
        $supplier = Party::factory()->supplier()->create();
        $this->actingAs($admin)->post('/purchases', [
            'party_id' => $supplier->id, 'purchase_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => '150.00']],
            'paid_amount' => '600.00', 'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();
        $this->assertSame(4, $product->fresh()->stockQuantity());
    }

    public function test_validation_errors_are_returned_as_json()
    {
        Product::factory()->create(['sku' => 'COV-IP15']);

        $this->actingAs($this->admin())->postJson('/products/quick', $this->payload(['name' => '', 'unit_id' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sku', 'unit_id']);
    }

    public function test_requires_the_manage_products_permission()
    {
        $user = $this->generalUser();
        $user->givePermissionTo(Permission::PurchasesCreate->value);

        $this->actingAs($user)->getJson('/products/quick/options')->assertForbidden();
        $this->actingAs($user)->postJson('/products/quick', $this->payload())->assertForbidden();
        $this->assertSame(0, Product::count());

        $user->givePermissionTo(Permission::ProductsManage->value);
        $this->actingAs($user)->postJson('/products/quick', $this->payload())->assertCreated();
    }
}
