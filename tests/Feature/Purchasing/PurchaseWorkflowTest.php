<?php

namespace Tests\Feature\Purchasing;

use App\Domain\Inventory\StockService;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Enums\PaymentStatus;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class PurchaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Party $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->supplier = Party::factory()->supplier()->create();
    }

    /**
     * @param  list<array{0: Product, 1: int, 2: string}>  $lines
     */
    private function purchase(array $lines, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post('/purchases', array_merge([
            'party_id' => $this->supplier->id,
            'purchase_date' => today()->toDateString(),
            'supplier_invoice_no' => 'INV-1',
            'discount' => '0',
            'items' => array_map(fn ($line) => ['product_id' => $line[0]->id, 'quantity' => $line[1], 'unit_cost' => $line[2]], $lines),
            'paid_amount' => '0',
            'payment_method' => 'CASH',
        ], $overrides));
    }

    private function balance(Party $party): string
    {
        return $party->fresh()->balance;
    }

    private function stock(Product $product): int
    {
        return app(StockService::class)->balance($product);
    }

    public function test_cash_purchase_increases_stock_and_leaves_nothing_payable()
    {
        $phone = Product::factory()->create();
        $case = Product::factory()->withStock(2)->create();

        $response = $this->purchase([[$phone, 3, '15000.00'], [$case, 10, '150.00']], ['paid_amount' => '46500.00']);

        $purchase = Purchase::sole();
        $response->assertRedirect("/purchases/{$purchase->id}")->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('/^PUR-\d{6}-000001$/', $purchase->purchase_no);
        $this->assertSame('46500.00', $purchase->total);
        $this->assertSame('46500.00', $purchase->paid_amount);
        $this->assertSame('0.00', $purchase->due_amount);
        $this->assertSame(PaymentStatus::Paid, $purchase->payment_status);

        // Stock in, valued at cost and referencing the purchase.
        $this->assertSame(3, $this->stock($phone));
        $this->assertSame(12, $this->stock($case));
        $movement = StockMovement::where('product_id', $phone->id)->where('type', 'PURCHASE_IN')->sole();
        $this->assertSame('15000.00', $movement->unit_cost);
        $this->assertTrue($movement->reference->is($purchase));

        // Ledger: payable then payment → settled.
        $entries = $this->supplier->ledgerEntries()->orderBy('id')->get();
        $this->assertSame(['PURCHASE', 'PURCHASE_PAYMENT'], $entries->pluck('entry_type.value')->all());
        $this->assertSame('46500.00', $entries[0]->credit);
        $this->assertSame('46500.00', $entries[1]->debit);
        $this->assertSame('0.00', $this->balance($this->supplier));

        $payment = Payment::sole();
        $this->assertSame('46500.00', $payment->allocated_amount);
        $this->assertSame($this->admin->id, $purchase->created_by);
    }

    public function test_partial_purchase_leaves_the_remainder_payable()
    {
        $product = Product::factory()->create();

        $this->purchase([[$product, 10, '100.00']], ['paid_amount' => '400.00'])->assertSessionHasNoErrors();

        $purchase = Purchase::sole();
        $this->assertSame(PaymentStatus::Partial, $purchase->payment_status);
        $this->assertSame('400.00', $purchase->paid_amount);
        $this->assertSame('600.00', $purchase->due_amount);
        $this->assertSame('-600.00', $this->balance($this->supplier));
    }

    public function test_due_purchase_creates_full_payable_and_no_payment()
    {
        $product = Product::factory()->create();

        $this->purchase([[$product, 5, '200.00']])->assertSessionHasNoErrors();

        $purchase = Purchase::sole();
        $this->assertSame(PaymentStatus::Due, $purchase->payment_status);
        $this->assertSame('1000.00', $purchase->due_amount);
        $this->assertSame(0, Payment::count());
        $this->assertSame('-1000.00', $this->balance($this->supplier));
        $this->assertSame(5, $this->stock($product));
    }

    public function test_header_discount_reduces_total_and_stock_cost()
    {
        $product = Product::factory()->create();

        $this->purchase([[$product, 4, '250.00']], ['discount' => '100.00'])->assertSessionHasNoErrors();

        $purchase = Purchase::sole();
        $this->assertSame('1000.00', $purchase->subtotal);
        $this->assertSame('900.00', $purchase->total);
        $this->assertSame('225.00', StockMovement::where('type', 'PURCHASE_IN')->sole()->unit_cost);
        $this->assertSame('-900.00', $this->balance($this->supplier));
    }

    public function test_purchase_is_atomic_when_a_step_fails()
    {
        $product = Product::factory()->create();

        // Over-payment is rejected by validation; nothing may be written.
        $this->purchase([[$product, 1, '100.00']], ['paid_amount' => '150.00'])->assertSessionHasErrors('paid_amount');

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, $this->supplier->ledgerEntries()->count());
        $this->assertSame(0, $this->stock($product));
    }

    public function test_failure_inside_the_transaction_rolls_everything_back()
    {
        $product = Product::factory()->create();
        // Advance applied (500) leaves only 500 payable, so paying 800 now fails inside the action.
        $this->actingAs($this->admin)->post('/supplier-advances', [
            'party_id' => $this->supplier->id, 'amount' => '500.00', 'method' => 'CASH', 'date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->purchase([[$product, 1, '1000.00']], ['paid_amount' => '800.00', 'apply_advance' => true])
            ->assertSessionHasErrors('paid_amount');

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, $this->stock($product));
        $this->assertSame('0.00', Payment::sole()->allocated_amount);
        $this->assertSame('500.00', $this->balance($this->supplier));
        $this->assertSame(1, $this->supplier->ledgerEntries()->count());
    }

    public function test_purchase_validation()
    {
        $customer = Party::factory()->customer()->create();
        $inactiveProduct = Product::factory()->inactive()->create();
        $product = Product::factory()->create();

        $this->purchase([[$product, 1, '10']], ['party_id' => $customer->id])->assertSessionHasErrors('party_id');
        $this->purchase([])->assertSessionHasErrors('items');
        $this->purchase([[$inactiveProduct, 1, '10']])->assertSessionHasErrors('items.0.product_id');
        $this->purchase([[$product, 1, '10'], [$product, 2, '10']])->assertSessionHasErrors('items.1.product_id');
        $this->purchase([[$product, 0, '10.123']])->assertSessionHasErrors(['items.0.quantity', 'items.0.unit_cost']);
        $this->purchase([[$product, 1, '10']], ['discount' => '11'])->assertSessionHasErrors('discount');
        $this->purchase([[$product, 1, '10']], ['purchase_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('purchase_date');
        $this->purchase([[$product, 1, '10']], ['paid_amount' => '5', 'payment_method' => ''])->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Purchase::count());
    }

    public function test_purchase_numbers_are_sequential()
    {
        $product = Product::factory()->create();

        $this->purchase([[$product, 1, '10']]);
        $this->purchase([[$product, 1, '10']]);

        $this->assertSame(['000001', '000002'], Purchase::orderBy('id')->pluck('purchase_no')->map(fn ($no) => substr($no, -6))->all());
    }

    public function test_purchases_cannot_be_deleted_or_edited_through_routes()
    {
        $product = Product::factory()->create();
        $this->purchase([[$product, 1, '10']]);
        $purchase = Purchase::sole();

        $this->actingAs($this->admin)->delete("/purchases/{$purchase->id}")->assertMethodNotAllowed();
        $this->actingAs($this->admin)->put("/purchases/{$purchase->id}", [])->assertMethodNotAllowed();

        $this->expectException(\LogicException::class);
        $purchase->delete();
    }

    public function test_ledger_balance_matches_cached_balance_after_workflow()
    {
        $product = Product::factory()->create();
        $this->purchase([[$product, 10, '100.00']], ['paid_amount' => '300.00']);

        $ledger = app(PartyLedgerService::class);
        $this->assertSame($ledger->ledgerBalance($this->supplier), $this->balance($this->supplier));
        $this->artisan('ledger:reconcile')->assertSuccessful();
        $this->artisan('inventory:reconcile')->assertSuccessful();
    }
}
