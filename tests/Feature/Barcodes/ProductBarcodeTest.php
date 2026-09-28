<?php

namespace Tests\Feature\Barcodes;

use App\Enums\Permission;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T035 — barcode generation, validation and rendering.
 */
class ProductBarcodeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo(Permission::ProductsView->value, Permission::ProductsManage->value);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'USB-C Cable', 'sku' => 'CBL-001', 'barcode' => '', 'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id, 'purchase_price' => '100', 'retail_price' => '150', 'wholesale_price' => '130',
            'reorder_level' => 5, 'is_active' => true,
        ], $overrides);
    }

    public function test_generate_a_barcode_for_a_product_without_one()
    {
        $product = Product::factory()->create(['barcode' => null]);

        $this->actingAs($this->manager)->post("/products/{$product->id}/barcode")->assertSessionHasNoErrors();

        $expected = '20'.str_pad((string) $product->id, 10, '0', STR_PAD_LEFT);
        $this->assertSame($expected, $product->fresh()->barcode);
        $this->assertSame(12, strlen($expected));
    }

    public function test_an_existing_barcode_is_never_replaced()
    {
        $product = Product::factory()->create(['barcode' => '8901234567890']);

        $this->actingAs($this->manager)->post("/products/{$product->id}/barcode")->assertSessionHasErrors('barcode');
        $this->assertSame('8901234567890', $product->fresh()->barcode);
    }

    public function test_generation_avoids_a_manually_used_value()
    {
        $product = Product::factory()->create(['barcode' => null]);
        Product::factory()->create(['barcode' => '20'.str_pad((string) $product->id, 10, '0', STR_PAD_LEFT)]);

        $this->actingAs($this->manager)->post("/products/{$product->id}/barcode")->assertSessionHasNoErrors();

        $barcode = $product->fresh()->barcode;
        $this->assertMatchesRegularExpression('/^20\d{10}$/', $barcode);
        $this->assertSame(1, Product::where('barcode', $barcode)->count());
    }

    public function test_json_generation_for_the_label_builder()
    {
        $product = Product::factory()->create(['barcode' => null]);

        $this->actingAs($this->manager)->postJson("/products/{$product->id}/barcode")
            ->assertOk()
            ->assertJsonPath('data.barcode', $product->fresh()->barcode);
    }

    public function test_generate_on_create_and_on_edit_when_left_empty()
    {
        $this->actingAs($this->manager)->post('/products', $this->productPayload(['generate_barcode' => true]))->assertSessionHasNoErrors();
        $product = Product::where('sku', 'CBL-001')->sole();
        $this->assertSame('20'.str_pad((string) $product->id, 10, '0', STR_PAD_LEFT), $product->barcode);

        // A typed barcode wins over the option.
        $this->actingAs($this->manager)->post('/products', $this->productPayload(['sku' => 'CBL-002', 'barcode' => 'ABC-1', 'generate_barcode' => true]))
            ->assertSessionHasNoErrors();
        $this->assertSame('ABC-1', Product::where('sku', 'CBL-002')->value('barcode'));

        // Without the option the barcode stays empty.
        $this->actingAs($this->manager)->post('/products', $this->productPayload(['sku' => 'CBL-003']))->assertSessionHasNoErrors();
        $plain = Product::where('sku', 'CBL-003')->sole();
        $this->assertNull($plain->barcode);

        $this->actingAs($this->manager)->put("/products/{$plain->id}", $this->productPayload([
            'sku' => 'CBL-003', 'category_id' => $plain->category_id, 'unit_id' => $plain->unit_id, 'generate_barcode' => true,
        ]))->assertSessionHasNoErrors();
        $this->assertNotNull($plain->fresh()->barcode);
    }

    public function test_barcode_validation_still_applies()
    {
        $this->actingAs($this->manager)->post('/products', $this->productPayload(['barcode' => 'bad barcode!']))->assertSessionHasErrors('barcode');
        $this->actingAs($this->manager)->post('/products', $this->productPayload(['generate_barcode' => 'maybe']))->assertSessionHasErrors('generate_barcode');
    }

    public function test_product_page_renders_the_barcode()
    {
        $product = Product::factory()->create(['barcode' => '200000000042']);
        $none = Product::factory()->create(['barcode' => null]);

        $this->actingAs($this->manager)->get("/products/{$product->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('barcodeImage', fn ($uri) => str_starts_with($uri, 'data:image/svg+xml;base64,')));

        $this->actingAs($this->manager)->get("/products/{$none->id}")
            ->assertInertia(fn (Assert $page) => $page->where('barcodeImage', null));
    }

    public function test_generation_needs_product_management()
    {
        $product = Product::factory()->create(['barcode' => null]);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ProductsView->value, Permission::BarcodesPrint->value);

        $this->actingAs($viewer)->post("/products/{$product->id}/barcode")->assertForbidden();
        $this->assertNull($product->fresh()->barcode);
    }
}
