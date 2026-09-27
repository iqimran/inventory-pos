<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Subcategory;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        $category = Category::factory()->create();

        return array_merge([
            'category_id' => $category->id,
            'subcategory_id' => Subcategory::factory()->for($category)->create()->id,
            'brand_id' => Brand::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Samsung 25W Charger',
            'sku' => ' chg-sam-25w ',
            'barcode' => '8801643000011',
            'description' => 'Fast charger',
            'purchase_price' => '850.50',
            'retail_price' => '1200',
            'wholesale_price' => '1050.00',
            'reorder_level' => 5,
            'is_active' => true,
        ], $overrides);
    }

    public function test_admin_can_create_a_product()
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/products', $this->payload());

        $product = Product::firstOrFail();
        $response->assertRedirect("/products/{$product->id}")->assertSessionHas('success');

        $this->assertSame('CHG-SAM-25W', $product->sku);
        $this->assertSame('8801643000011', $product->barcode);
        $this->assertSame('850.50', $product->purchase_price);
        $this->assertSame('1200.00', $product->retail_price);
        $this->assertSame($admin->id, $product->created_by);
    }

    public function test_new_product_starts_with_zero_stock_and_no_movements()
    {
        $this->actingAs($this->admin())->post('/products', $this->payload(['stock' => 50, 'quantity' => 50]));

        $product = Product::firstOrFail();
        $this->assertSame(0, ProductStock::find($product->id)->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_sku_and_barcode_must_be_unique()
    {
        Product::factory()->create(['sku' => 'CHG-SAM-25W', 'barcode' => '8801643000011']);

        $this->actingAs($this->admin())->post('/products', $this->payload())
            ->assertSessionHasErrors(['sku', 'barcode']);
    }

    public function test_barcode_is_optional_and_multiple_products_may_omit_it()
    {
        $this->actingAs($this->admin())->post('/products', $this->payload(['barcode' => '', 'sku' => 'A1']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post('/products', $this->payload(['barcode' => null, 'sku' => 'A2']))->assertSessionHasNoErrors();

        $this->assertSame(2, Product::whereNull('barcode')->count());
    }

    public function test_product_input_is_validated()
    {
        $this->actingAs($this->admin())->post('/products', $this->payload([
            'purchase_price' => '-1',
            'retail_price' => '10.123',
            'wholesale_price' => 'abc',
            'reorder_level' => -2,
            'sku' => 'bad sku!',
            'barcode' => 'has space',
            'unit_id' => null,
        ]))->assertSessionHasErrors(['purchase_price', 'retail_price', 'wholesale_price', 'reorder_level', 'sku', 'barcode', 'unit_id']);
    }

    public function test_subcategory_must_belong_to_the_selected_category()
    {
        $foreign = Subcategory::factory()->create();

        $this->actingAs($this->admin())->post('/products', $this->payload(['subcategory_id' => $foreign->id]))
            ->assertSessionHasErrors('subcategory_id');
    }

    public function test_inactive_master_data_cannot_be_assigned_to_new_products()
    {
        $this->actingAs($this->admin())->post('/products', $this->payload([
            'category_id' => Category::factory()->inactive()->create()->id,
            'subcategory_id' => null,
            'brand_id' => Brand::factory()->inactive()->create()->id,
            'unit_id' => Unit::factory()->inactive()->create()->id,
        ]))->assertSessionHasErrors(['category_id', 'brand_id', 'unit_id']);
    }

    public function test_product_keeps_its_now_inactive_category_when_edited()
    {
        $product = Product::factory()->create();
        $product->category->update(['is_active' => false]);

        $this->actingAs($this->admin())->put("/products/{$product->id}", $this->payload([
            'category_id' => $product->category_id,
            'subcategory_id' => null,
            'unit_id' => $product->unit_id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => 'Renamed',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $product->fresh()->name);
    }

    public function test_updating_a_product_never_changes_stock()
    {
        $product = Product::factory()->withStock(10)->create();
        $admin = $this->admin();

        $this->actingAs($admin)->put("/products/{$product->id}", $this->payload([
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'stock' => 999,
            'quantity' => 999,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(10, ProductStock::find($product->id)->quantity);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame($admin->id, $product->fresh()->updated_by);
    }

    public function test_product_detail_shows_stock_and_history()
    {
        $product = Product::factory()->withStock(7)->create();

        $this->actingAs($this->generalUser())->get("/products/{$product->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('products/show')
                ->where('product.data.stock', 7)
                ->has('movements.data', 1)
                ->where('movements.data.0.quantity', 7)
                ->where('movements.data.0.type', 'ADJUSTMENT_IN'));
    }

    public function test_create_and_edit_forms_render_for_managers()
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->get('/products/create')
            ->assertInertia(fn (Assert $page) => $page->component('products/create')->has('options.units'));
        $this->actingAs($this->admin())->get("/products/{$product->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->component('products/edit')->where('product.data.id', $product->id));
    }

    public function test_product_without_stock_history_can_be_deleted()
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->delete("/products/{$product->id}")->assertRedirect('/products');

        $this->assertModelMissing($product);
    }

    public function test_product_with_stock_history_cannot_be_deleted()
    {
        $product = Product::factory()->withStock(3)->create();

        $this->actingAs($this->admin())->delete("/products/{$product->id}")->assertSessionHasErrors('record');

        $this->assertModelExists($product);
    }

    public function test_general_user_can_view_but_not_manage_products()
    {
        $user = $this->generalUser();
        $product = Product::factory()->create();

        $this->actingAs($user)->get('/products')->assertOk();
        $this->actingAs($user)->get('/products/create')->assertForbidden();
        $this->actingAs($user)->post('/products', $this->payload())->assertForbidden();
        $this->actingAs($user)->get("/products/{$product->id}/edit")->assertForbidden();
        $this->actingAs($user)->put("/products/{$product->id}", $this->payload())->assertForbidden();
        $this->actingAs($user)->delete("/products/{$product->id}")->assertForbidden();
    }
}
