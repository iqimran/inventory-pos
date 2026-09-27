<?php

namespace Tests\Feature\Sales;

use App\Domain\Inventory\StockService;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
    }

    private function purchase(Product $product, int $quantity, string $cost): void
    {
        $supplier = Party::factory()->supplier()->create();

        $this->actingAs($this->admin)->post('/purchases', [
            'party_id' => $supplier->id,
            'purchase_date' => today()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost]],
            'paid_amount' => '0',
        ])->assertSessionHasNoErrors();
    }

    private function sell(Product $product, int $quantity): Sale
    {
        $this->actingAs($this->admin)->post('/sales', [
            'sale_type' => 'RETAIL',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'paid_amount' => bcmul($product->retail_price, (string) $quantity, 2),
            'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->first();
    }

    public function test_purchases_maintain_a_moving_weighted_average_cost()
    {
        $product = Product::factory()->create();

        $this->purchase($product, 10, '100.00');
        $this->purchase($product, 10, '130.00');

        $this->assertSame('115.00', app(StockService::class)->averageCost($product));
    }

    public function test_sale_snapshots_the_average_cost_and_later_changes_do_not_rewrite_it()
    {
        $product = Product::factory()->create(['retail_price' => '200.00', 'purchase_price' => '1.00']);
        $this->purchase($product, 10, '100.00');
        $this->purchase($product, 10, '130.00');

        $sale = $this->sell($product, 4);
        $item = $sale->items()->sole();

        $this->assertSame('115.00', $item->unit_cost);
        $this->assertSame('460.00', $item->cost_total);
        $this->assertSame('460.00', $sale->cost_total);
        $this->assertSame('115.00', StockMovement::where('type', 'SALE_OUT')->sole()->unit_cost);

        // Later purchases and a product price edit must not change the historical snapshot.
        $this->purchase($product, 16, '200.00');
        $product->update(['purchase_price' => '999.00']);

        $this->assertSame('115.00', $item->fresh()->unit_cost);
        $this->assertSame('460.00', $sale->fresh()->cost_total);
        // New average: (16 × 115 + 16 × 200) / 32 = 157.50
        $this->assertSame('157.50', app(StockService::class)->averageCost($product));
    }

    public function test_falls_back_to_product_purchase_price_without_costed_stock()
    {
        $product = Product::factory()->withStock(5)->create(['purchase_price' => '80.00', 'retail_price' => '120.00']);

        $item = $this->sell($product, 1)->items()->sole();

        $this->assertSame('80.00', $item->unit_cost);
    }

    public function test_sale_cost_is_hidden_from_users_without_purchase_access()
    {
        $product = Product::factory()->withStock(5)->create(['purchase_price' => '80.00', 'retail_price' => '120.00']);
        $sale = $this->sell($product, 1);

        $this->actingAs($this->generalUser())->get("/sales/{$sale->id}")
            ->assertInertia(fn ($page) => $page->missing('sale.data.cost_total')->missing('sale.data.items.0.unit_cost'));
    }
}
