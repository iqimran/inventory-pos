<?php

namespace Tests\Feature\Barcodes;

use App\Enums\LabelLayout;
use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T036 — single and bulk barcode label printing.
 */
class BarcodeLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $printer;

    private Product $cable;

    private Product $charger;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.name' => 'IQ Mobile']);
        $this->printer = User::factory()->create();
        $this->printer->givePermissionTo(Permission::BarcodesPrint->value);
        $this->cable = Product::factory()->create(['name' => 'USB-C Cable', 'sku' => 'CBL-001', 'barcode' => '200000000001', 'retail_price' => '150.00', 'wholesale_price' => '130.00']);
        $this->charger = Product::factory()->create(['name' => '20W Charger', 'sku' => 'CHG-020', 'barcode' => 'CHG20W', 'retail_price' => '1200.00', 'wholesale_price' => '1050.00']);
    }

    private function print(array $query, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->printer)->get('/barcodes/labels/print?'.http_build_query(array_merge([
            'layout' => 'ROLL_38X25', 'price' => 'retail', 'show_sku' => 1, 'show_shop' => 0,
        ], $query)));
    }

    public function test_builder_page_with_a_preselected_product()
    {
        $this->actingAs($this->printer)->get("/barcodes/labels?products[]={$this->cable->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('barcodes/labels')
                ->has('layouts', 3)
                ->has('preselected', 1)
                ->where('preselected.0.barcode', '200000000001'));
    }

    public function test_single_product_labels()
    {
        $this->print(['items' => [['product_id' => $this->cable->id, 'quantity' => 3]]])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('barcodes/print')
                ->where('total', 3)
                ->has('labels', 1)
                ->where('labels.0.name', 'USB-C Cable')
                ->where('labels.0.sku', 'CBL-001')
                ->where('labels.0.barcode', '200000000001')
                ->where('labels.0.price', '150.00')
                ->where('labels.0.quantity', 3)
                ->where('labels.0.image', fn ($uri) => str_starts_with($uri, 'data:image/svg+xml;base64,')
                    && str_contains(base64_decode(substr($uri, 26)), '<desc>200000000001</desc>'))
                ->where('layout.value', 'ROLL_38X25')
                ->where('layout.label_width', 38)
                ->where('layout.label_height', 25)
                ->where('options.show_sku', true)
                ->where('options.show_shop', false)
                ->where('shopName', 'IQ Mobile'));
    }

    public function test_bulk_labels_on_an_a4_sheet_with_wholesale_price()
    {
        $this->print([
            'layout' => 'A4_3X7', 'price' => 'wholesale', 'show_shop' => 1,
            'items' => [['product_id' => $this->cable->id, 'quantity' => 10], ['product_id' => $this->charger->id, 'quantity' => 25]],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('total', 35)
                ->has('labels', 2)
                ->where('labels.0.price', '130.00')
                ->where('labels.1.price', '1050.00')
                ->where('labels.1.barcode', 'CHG20W')
                ->where('layout.columns', 3)
                ->where('layout.rows', 7)
                ->where('options.show_shop', true));
    }

    public function test_labels_without_price()
    {
        $this->print(['price' => 'none', 'items' => [['product_id' => $this->cable->id, 'quantity' => 1]]])
            ->assertInertia(fn (Assert $page) => $page->where('labels.0.price', null));
    }

    public function test_validation()
    {
        $noBarcode = Product::factory()->create(['name' => 'Screen guard', 'barcode' => null]);

        $this->print(['items' => []])->assertSessionHasErrors('items');
        $this->print(['items' => [['product_id' => $this->cable->id, 'quantity' => 0]]])->assertSessionHasErrors('items.0.quantity');
        $this->print(['items' => [['product_id' => 999999, 'quantity' => 1]]])->assertSessionHasErrors('items.0.product_id');
        $this->print(['layout' => 'HUGE', 'items' => [['product_id' => $this->cable->id, 'quantity' => 1]]])->assertSessionHasErrors('layout');
        $this->print(['price' => 'cost', 'items' => [['product_id' => $this->cable->id, 'quantity' => 1]]])->assertSessionHasErrors('price');
        $this->print(['items' => [['product_id' => $this->cable->id, 'quantity' => 1500], ['product_id' => $this->charger->id, 'quantity' => 600]]])
            ->assertSessionHasErrors('items');
        $this->print(['items' => [['product_id' => $noBarcode->id, 'quantity' => 1]]])
            ->assertSessionHasErrors(['items' => 'Generate a barcode first for: Screen guard.']);
    }

    public function test_every_layout_has_consistent_geometry()
    {
        foreach (LabelLayout::cases() as $layout) {
            $g = $layout->geometry();
            $usedWidth = $g['margin_left'] + $g['columns'] * $g['label_width'] + ($g['columns'] - 1) * $g['gap_x'];
            $usedHeight = $g['margin_top'] + $g['rows'] * $g['label_height'] + ($g['rows'] - 1) * $g['gap_y'];

            $this->assertLessThanOrEqual($g['page_width'] + 0.01, $usedWidth, $layout->value);
            $this->assertLessThanOrEqual($g['page_height'] + 0.01, $usedHeight, $layout->value);
        }
    }

    public function test_needs_the_barcode_print_permission()
    {
        $clerk = User::factory()->create();
        $clerk->givePermissionTo(Permission::ProductsView->value);

        $this->actingAs($clerk)->get('/barcodes/labels')->assertForbidden();
        $this->print(['items' => [['product_id' => $this->cable->id, 'quantity' => 1]]], $clerk)->assertForbidden();

        // The label builder can search and scan products.
        $this->actingAs($this->printer)->getJson('/pos/products?q=Cable')->assertOk()->assertJsonPath('data.0.sku', 'CBL-001');
        $this->actingAs($this->printer)->getJson('/pos/products/lookup/CHG20W')->assertOk()->assertJsonPath('data.name', '20W Charger');
    }
}
