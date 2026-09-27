<?php

namespace Tests\Feature\Sales;

use App\Domain\Inventory\StockService;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SaleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Party $customer;

    private Product $phone;

    private Product $case;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->generalUser();
        $this->customer = Party::factory()->customer()->create();
        $this->phone = Product::factory()->withStock(10)->create(['retail_price' => '18000.00', 'wholesale_price' => '17000.00']);
        $this->case = Product::factory()->withStock(50)->create(['retail_price' => '300.00', 'wholesale_price' => '220.00']);
    }

    private function sell(array $items, array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->cashier)->post('/sales', array_merge([
            'sale_type' => 'RETAIL',
            'party_id' => null,
            'items' => $items,
            'discount' => '0',
            'paid_amount' => '0',
            'payment_method' => 'CASH',
        ], $overrides));
    }

    private function stock(Product $product): int
    {
        return app(StockService::class)->balance($product);
    }

    public function test_retail_cash_sale_decreases_stock_and_has_zero_due()
    {
        $response = $this->sell(
            [['product_id' => $this->phone->id, 'quantity' => 1], ['product_id' => $this->case->id, 'quantity' => 2]],
            ['paid_amount' => '18600.00', 'tendered_amount' => '19000'],
        );

        $sale = Sale::sole();
        $response->assertRedirect("/sales/{$sale->id}/receipt?new=1")->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('/^SALE-\d{6}-000001$/', $sale->invoice_no);
        $this->assertSame('RETAIL', $sale->sale_type->value);
        $this->assertSame('18600.00', $sale->total);
        $this->assertSame('0.00', $sale->due_amount);
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertSame('400.00', $sale->change_amount);
        $this->assertSame($this->cashier->id, $sale->created_by);

        $this->assertSame(9, $this->stock($this->phone));
        $this->assertSame(48, $this->stock($this->case));
        $this->assertSame(2, StockMovement::where('type', 'SALE_OUT')->count());
        $this->assertTrue(StockMovement::where('type', 'SALE_OUT')->first()->reference->is($sale));

        // Walk-in: the payment is recorded without a party and no ledger entries are made.
        $payment = Payment::sole();
        $this->assertNull($payment->party_id);
        $this->assertSame('SALE_PAYMENT', $payment->purpose->value);
        $this->assertSame('IN', $payment->direction->value);
        $this->assertSame(0, PartyLedgerEntry::count());
    }

    public function test_wholesale_sale_uses_wholesale_prices()
    {
        $this->sell([['product_id' => $this->case->id, 'quantity' => 10]], ['sale_type' => 'WHOLESALE', 'paid_amount' => '2200.00'])
            ->assertSessionHasNoErrors();

        $item = Sale::sole()->items()->sole();
        $this->assertSame('220.00', $item->unit_price);
        $this->assertSame('220.00', $item->list_price);
        $this->assertFalse($item->price_overridden);
        $this->assertSame('WHOLESALE', Sale::sole()->sale_type->value);
    }

    public function test_client_prices_are_ignored_unless_override_is_permitted()
    {
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1, 'unit_price' => '1.00']], ['paid_amount' => '1.00'])
            ->assertSessionHasErrors('items.0.unit_price');
        $this->assertSame(0, Sale::count());

        $this->cashier->givePermissionTo(Permission::SalesPriceOverride->value);
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1, 'unit_price' => '250.00']], ['paid_amount' => '250.00'])
            ->assertSessionHasNoErrors();

        $item = Sale::sole()->items()->sole();
        $this->assertSame('250.00', $item->unit_price);
        $this->assertSame('300.00', $item->list_price);
        $this->assertTrue($item->price_overridden);
    }

    public function test_line_and_invoice_discounts()
    {
        $this->sell(
            [['product_id' => $this->phone->id, 'quantity' => 1, 'discount' => '500.00'], ['product_id' => $this->case->id, 'quantity' => 1]],
            ['discount' => '100.00', 'paid_amount' => '17700.00'],
        )->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('18300.00', $sale->subtotal);
        $this->assertSame('500.00', $sale->items_discount);
        $this->assertSame('100.00', $sale->discount);
        $this->assertSame('17700.00', $sale->total);
        $this->assertSame('17700.00', bcadd($sale->items[0]->line_total, $sale->items[1]->line_total, 2));
    }

    public function test_discounts_are_bounded()
    {
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1, 'discount' => '300.01']], ['paid_amount' => '0'])
            ->assertSessionHasErrors('items.0.discount');
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['discount' => '301', 'paid_amount' => '0'])
            ->assertSessionHasErrors('discount');

        $this->assertSame(0, Sale::count());
    }

    public function test_partial_sale_to_customer_creates_receivable_for_the_due()
    {
        $this->sell([['product_id' => $this->phone->id, 'quantity' => 1]], ['party_id' => $this->customer->id, 'paid_amount' => '10000.00'])
            ->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame(PaymentStatus::Partial, $sale->payment_status);
        $this->assertSame('8000.00', $sale->due_amount);

        $entries = $this->customer->ledgerEntries()->orderBy('id')->get();
        $this->assertSame(['SALE', 'CUSTOMER_PAYMENT'], $entries->pluck('entry_type.value')->all());
        $this->assertSame('18000.00', $entries[0]->debit);
        $this->assertSame('10000.00', $entries[1]->credit);
        $this->assertSame('8000.00', $this->customer->fresh()->balance);
    }

    public function test_full_due_sale_creates_full_receivable_and_no_payment()
    {
        $this->sell([['product_id' => $this->case->id, 'quantity' => 3]], ['party_id' => $this->customer->id, 'paid_amount' => '0'])
            ->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame(PaymentStatus::Due, $sale->payment_status);
        $this->assertNull($sale->payment_method);
        $this->assertSame(0, Payment::count());
        $this->assertSame('900.00', $this->customer->fresh()->balance);
    }

    public function test_cash_sale_to_known_customer_leaves_balance_unchanged()
    {
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['party_id' => $this->customer->id, 'paid_amount' => '300.00'])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->customer->fresh()->balance);
        $this->assertSame(2, $this->customer->ledgerEntries()->count());
    }

    public function test_walk_in_sale_must_be_paid_in_full()
    {
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['paid_amount' => '100.00'])->assertSessionHasErrors('party_id');

        $this->assertSame(0, Sale::count());
        $this->assertSame(50, $this->stock($this->case));
    }

    public function test_insufficient_stock_rejects_the_whole_sale()
    {
        $this->sell(
            [['product_id' => $this->case->id, 'quantity' => 1], ['product_id' => $this->phone->id, 'quantity' => 11]],
            ['party_id' => $this->customer->id],
        )->assertSessionHasErrors('quantity');

        $this->assertSame(0, Sale::count());
        $this->assertSame(50, $this->stock($this->case));
        $this->assertSame(0, $this->customer->ledgerEntries()->count());
        $this->assertSame(0, Payment::count());
    }

    public function test_sale_validation()
    {
        $supplier = Party::factory()->supplier()->create();
        $inactive = Product::factory()->withStock(5)->create(['is_active' => false]);

        $this->sell([])->assertSessionHasErrors('items');
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['sale_type' => 'VIP'])->assertSessionHasErrors('sale_type');
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['party_id' => $supplier->id])->assertSessionHasErrors('party_id');
        $this->sell([['product_id' => $inactive->id, 'quantity' => 1]], ['paid_amount' => '1'])->assertSessionHasErrors('items.0.product_id');
        $this->sell([['product_id' => $this->case->id, 'quantity' => 0]])->assertSessionHasErrors('items.0.quantity');
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1], ['product_id' => $this->case->id, 'quantity' => 1]])
            ->assertSessionHasErrors('items.1.product_id');
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['paid_amount' => '300.01'])->assertSessionHasErrors('paid_amount');
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['paid_amount' => '300', 'tendered_amount' => '200'])
            ->assertSessionHasErrors('tendered_amount');
        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['paid_amount' => '300', 'payment_method' => 'IOU'])
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Sale::count());
    }

    public function test_invoice_numbers_are_sequential_and_unique()
    {
        foreach (range(1, 3) as $i) {
            $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['paid_amount' => '300']);
        }

        $this->assertSame(['000001', '000002', '000003'], Sale::orderBy('id')->pluck('invoice_no')->map(fn ($n) => substr($n, -6))->all());
    }

    public function test_sales_require_permission_and_are_immutable()
    {
        $nobody = User::factory()->create();

        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['paid_amount' => '300'], $nobody)->assertForbidden();
        $this->actingAs($nobody)->get('/pos')->assertForbidden();

        $this->sell([['product_id' => $this->case->id, 'quantity' => 1]], ['paid_amount' => '300']);
        $sale = Sale::sole();
        $this->actingAs($this->admin())->delete("/sales/{$sale->id}")->assertMethodNotAllowed();

        $this->expectException(\LogicException::class);
        $sale->delete();
    }

    public function test_invariants_hold_after_sales()
    {
        $this->sell([['product_id' => $this->phone->id, 'quantity' => 2]], ['party_id' => $this->customer->id, 'paid_amount' => '5000']);
        $this->sell([['product_id' => $this->case->id, 'quantity' => 5]], ['paid_amount' => '1500']);

        $this->artisan('inventory:reconcile')->assertSuccessful();
        $this->artisan('ledger:reconcile')->assertSuccessful();
    }
}
