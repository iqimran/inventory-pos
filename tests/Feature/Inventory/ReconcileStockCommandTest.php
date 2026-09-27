<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\StockService;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconcileStockCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_when_balances_match_the_ledger()
    {
        Product::factory()->withStock(5)->create();

        $this->artisan('inventory:reconcile')->assertSuccessful();
    }

    public function test_detects_and_repairs_a_tampered_balance()
    {
        $product = Product::factory()->withStock(5)->create();
        DB::table('product_stocks')->where('product_id', $product->id)->update(['quantity' => 99]);

        $this->artisan('inventory:reconcile')->assertFailed();
        $this->artisan('inventory:reconcile', ['--fix' => true])->assertSuccessful();

        $this->assertSame(5, app(StockService::class)->balance($product));
        $this->artisan('inventory:reconcile')->assertSuccessful();
    }
}
