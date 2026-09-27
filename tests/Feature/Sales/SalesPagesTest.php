<?php

namespace Tests\Feature\Sales;

use App\Enums\Permission;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Party $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->generalUser();
        $this->customer = Party::factory()->customer()->create(['name' => 'Karim']);
        $this->product = Product::factory()->withStock(100)->create(['retail_price' => '500.00', 'wholesale_price' => '450.00']);
    }

    private function sale(array $overrides = []): Sale
    {
        $this->actingAs($this->cashier)->post('/sales', array_merge([
            'sale_type' => 'RETAIL',
            'party_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'discount' => '50.00']],
            'discount' => '10.00',
            'paid_amount' => '500.00',
            'payment_method' => 'CASH',
            'tendered_amount' => '1000',
        ], $overrides))->assertSessionHasNoErrors();

        return Sale::latest('id')->first();
    }

    public function test_pos_page_exposes_mode_methods_and_override_permission()
    {
        $this->actingAs($this->cashier)->get('/pos?mode=WHOLESALE')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('pos/index')->where('mode', 'WHOLESALE')->where('canOverridePrice', false)->has('methods', 5));

        $this->cashier->givePermissionTo(Permission::SalesPriceOverride->value);
        $this->actingAs($this->cashier)->get('/pos')
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'RETAIL')->where('canOverridePrice', true));
    }

    public function test_receipt_contains_invoice_items_and_payment_summary()
    {
        config(['shop.name' => 'IQ Mobile', 'shop.phone' => '01700000000']);
        $sale = $this->sale();

        $this->actingAs($this->cashier)->get("/sales/{$sale->id}/receipt?new=1")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/receipt')
                ->where('justCompleted', true)
                ->where('shop.name', 'IQ Mobile')
                ->where('sale.data.invoice_no', $sale->invoice_no)
                ->has('sale.data.sold_at')
                ->where('sale.data.items.0.quantity', 2)
                ->where('sale.data.items.0.unit_price', '500.00')
                ->where('sale.data.items.0.line_discount', '50.00')
                ->where('sale.data.subtotal', '1000.00')
                ->where('sale.data.items_discount', '50.00')
                ->where('sale.data.discount', '10.00')
                ->where('sale.data.total', '940.00')
                ->where('sale.data.paid_amount', '500.00')
                ->where('sale.data.payment_method_label', 'Cash')
                ->where('sale.data.tendered_amount', '1000.00')
                ->where('sale.data.change_amount', '500.00')
                ->where('sale.data.due_amount', '440.00')
                ->where('sale.data.party.name', 'Karim'));
    }

    public function test_sale_detail_and_list_render_without_n_plus_one()
    {
        foreach (range(1, 8) as $i) {
            $this->sale();
        }

        DB::enableQueryLog();
        $this->actingAs($this->cashier)->get('/sales')
            ->assertInertia(fn (Assert $page) => $page->component('sales/index')->has('sales.data', 8));
        $this->assertLessThan(20, count(DB::getQueryLog()));

        $sale = Sale::first();
        $this->actingAs($this->cashier)->get("/sales/{$sale->id}")
            ->assertInertia(fn (Assert $page) => $page->component('sales/show')->has('sale.data.items', 1)->has('sale.data.allocations', 1));

        $this->actingAs($this->cashier)->get('/sales?status=PARTIAL&sale_type=RETAIL')
            ->assertInertia(fn (Assert $page) => $page->has('sales.data', 8));
        $this->actingAs($this->cashier)->get('/sales?sale_type=WHOLESALE')
            ->assertInertia(fn (Assert $page) => $page->has('sales.data', 0));
        $this->actingAs($this->cashier)->get('/sales?q='.strtolower($sale->invoice_no))
            ->assertInertia(fn (Assert $page) => $page->has('sales.data', 1));
    }

    public function test_customer_payment_pages_render()
    {
        $sale = $this->sale();

        $this->actingAs($this->cashier)->get("/customer-payments/create?party_id={$this->customer->id}&sale_id={$sale->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('customer-payments/create')
                ->where('customer.receivable', '440.00')
                ->where('customer.due_sales.0.invoice_no', $sale->invoice_no)
                ->where('saleId', $sale->id));
        $this->actingAs($this->cashier)->get('/customer-payments')
            ->assertInertia(fn (Assert $page) => $page->component('customer-payments/index')->has('payments.data', 1));
    }

    public function test_supplier_payment_is_not_viewable_as_customer_payment()
    {
        $supplier = Party::factory()->supplier()->create();
        $this->actingAs($this->admin())->post('/supplier-advances', [
            'party_id' => $supplier->id, 'amount' => '100', 'method' => 'CASH', 'date' => today()->toDateString(),
        ]);
        $payment = Payment::sole();

        $this->actingAs($this->admin())->get("/customer-payments/{$payment->id}")->assertNotFound();
    }

    public function test_page_access_is_permission_based()
    {
        $sale = $this->sale();
        $nobody = User::factory()->create();

        foreach (['/pos', '/sales', "/sales/{$sale->id}", "/sales/{$sale->id}/receipt", '/customer-payments', '/customer-payments/create'] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
    }
}
