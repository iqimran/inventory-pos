<?php

namespace Tests\Feature\Sales;

use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SaleReturnPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $customer = Party::factory()->customer()->create(['name' => 'Karim']);
        $product = Product::factory()->withStock(20)->create(['retail_price' => '500.00']);

        $this->actingAs($this->admin)->post('/sales', [
            'sale_type' => 'RETAIL', 'party_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'paid_amount' => '1500.00', 'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        $this->sale = Sale::sole()->load('items');
    }

    private function returnOne(): SaleReturn
    {
        $this->actingAs($this->admin)->post("/sales/{$this->sale->id}/returns", [
            'reason' => 'Faulty', 'items' => [['sale_item_id' => $this->sale->items->first()->id, 'quantity' => 1]],
            'refund_amount' => '500.00', 'refund_method' => 'CASH',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SaleReturn::latest('id')->first();
    }

    public function test_return_form_shows_eligible_quantities()
    {
        $this->returnOne();

        $this->actingAs($this->admin)->get("/sales/{$this->sale->id}/returns/create")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/returns/create')
                ->where('sale.data.items.0.quantity', 3)
                ->where('sale.data.items.0.returned_quantity', 1)
                ->where('sale.data.items.0.returnable_quantity', 2)
                ->where('customerBalance', '0.00')
                ->has('methods', 5));
    }

    public function test_store_redirects_to_the_return_slip()
    {
        $return = $this->returnOne();

        $this->actingAs($this->admin)->get("/sale-returns/{$return->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/returns/show')
                ->where('saleReturn.data.return_no', $return->return_no)
                ->where('saleReturn.data.sale.invoice_no', $this->sale->invoice_no)
                ->where('saleReturn.data.items.0.quantity', 1)
                ->where('saleReturn.data.refund_amount', '500.00')
                ->where('saleReturn.data.refund_method_label', 'Cash'));
    }

    public function test_sale_detail_shows_returns_and_returned_quantities()
    {
        $return = $this->returnOne();

        $this->actingAs($this->admin)->get("/sales/{$this->sale->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/show')
                ->where('sale.data.returned_amount', '500.00')
                ->where('sale.data.items.0.returned_quantity', 1)
                ->where('sale.data.returns.0.return_no', $return->return_no)
                // The original sale document is unchanged.
                ->where('sale.data.total', '1500.00')
                ->where('sale.data.items.0.quantity', 3));
    }

    public function test_returns_list_renders_without_n_plus_one()
    {
        foreach (range(1, 3) as $i) {
            $this->returnOne();
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/sale-returns')
            ->assertInertia(fn (Assert $page) => $page->component('sales/returns/index')->has('returns.data', 3));
        $this->assertLessThan(20, count(DB::getQueryLog()));
    }

    public function test_access_control()
    {
        $return = $this->returnOne();
        $nobody = User::factory()->create();

        foreach (['/sale-returns', "/sale-returns/{$return->id}", "/sales/{$this->sale->id}/returns/create"] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }

        // Cashiers can view returns but need returns.create to process one.
        $cashier = $this->generalUser();
        $this->actingAs($cashier)->get('/sale-returns')->assertOk();
        $this->actingAs($cashier)->get("/sales/{$this->sale->id}/returns/create")->assertForbidden();
    }
}
