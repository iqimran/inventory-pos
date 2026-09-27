<?php

namespace Tests\Feature\Purchasing;

use App\Domain\Inventory\StockService;
use App\Enums\PaymentStatus;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Party $supplier;

    private Product $phone;

    private Product $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->supplier = Party::factory()->supplier()->create();
        $this->phone = Product::factory()->create();
        $this->cable = Product::factory()->create();
    }

    private function purchase(string $paid = '0', string $discount = '0'): Purchase
    {
        $this->actingAs($this->admin)->post('/purchases', [
            'party_id' => $this->supplier->id,
            'purchase_date' => today()->toDateString(),
            'discount' => $discount,
            'items' => [
                ['product_id' => $this->phone->id, 'quantity' => 2, 'unit_cost' => '10000.00'],
                ['product_id' => $this->cable->id, 'quantity' => 10, 'unit_cost' => '100.00'],
            ],
            'paid_amount' => $paid,
            'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        return Purchase::latest('id')->first()->load('items');
    }

    private function returnItems(Purchase $purchase, array $lines, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post("/purchases/{$purchase->id}/returns", array_merge([
            'return_date' => today()->toDateString(),
            'reason' => 'Defective units',
            'items' => $lines,
        ], $overrides));
    }

    private function stock(Product $product): int
    {
        return app(StockService::class)->balance($product);
    }

    public function test_return_decreases_stock_and_reduces_the_payable()
    {
        $purchase = $this->purchase(); // 21000 due
        $phoneLine = $purchase->items->firstWhere('product_id', $this->phone->id);

        $this->returnItems($purchase, [['purchase_item_id' => $phoneLine->id, 'quantity' => 1]])
            ->assertRedirect("/purchases/{$purchase->id}")
            ->assertSessionHasNoErrors();

        $return = PurchaseReturn::sole();
        $this->assertMatchesRegularExpression('/^PRT-\d{6}-000001$/', $return->return_no);
        $this->assertSame('10000.00', $return->total);

        $this->assertSame(1, $this->stock($this->phone));
        $movement = StockMovement::where('type', 'PURCHASE_RETURN_OUT')->sole();
        $this->assertSame(-1, $movement->quantity);
        $this->assertTrue($movement->reference->is($return));

        $purchase->refresh();
        $this->assertSame('10000.00', $purchase->returned_amount);
        $this->assertSame('11000.00', $purchase->due_amount);
        $this->assertSame('-11000.00', $this->supplier->fresh()->balance);
        $this->assertSame('PURCHASE_RETURN', $this->supplier->ledgerEntries()->latest('id')->first()->entry_type->value);
    }

    public function test_returning_everything_settles_a_due_purchase()
    {
        $purchase = $this->purchase();

        $this->returnItems($purchase, $purchase->items->map(fn ($item) => ['purchase_item_id' => $item->id, 'quantity' => $item->quantity])->all())
            ->assertSessionHasNoErrors();

        $purchase->refresh();
        $this->assertSame('0.00', $purchase->due_amount);
        $this->assertSame(PaymentStatus::Paid, $purchase->payment_status);
        $this->assertSame('0.00', $this->supplier->fresh()->balance);
        $this->assertSame(0, $this->stock($this->phone));
        $this->assertSame(0, $this->stock($this->cable));
    }

    public function test_return_value_uses_net_cost_after_discount()
    {
        $purchase = $this->purchase(discount: '2100.00'); // 10% off 21000
        $cableLine = $purchase->items->firstWhere('product_id', $this->cable->id);

        $this->returnItems($purchase, [['purchase_item_id' => $cableLine->id, 'quantity' => 5]])->assertSessionHasNoErrors();

        $this->assertSame('450.00', PurchaseReturn::sole()->total);
    }

    public function test_cannot_return_more_than_purchased_across_returns()
    {
        $purchase = $this->purchase();
        $cableLine = $purchase->items->firstWhere('product_id', $this->cable->id);

        $this->returnItems($purchase, [['purchase_item_id' => $cableLine->id, 'quantity' => 6]])->assertSessionHasNoErrors();
        $this->returnItems($purchase, [['purchase_item_id' => $cableLine->id, 'quantity' => 5]])->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(1, PurchaseReturn::count());
        $this->assertSame(4, $this->stock($this->cable));
    }

    public function test_cannot_return_goods_that_are_no_longer_in_stock()
    {
        $purchase = $this->purchase();
        $phoneLine = $purchase->items->firstWhere('product_id', $this->phone->id);
        // Both phones have left stock (e.g. sold).
        $this->actingAs($this->admin)->post('/inventory/adjustments', [
            'product_id' => $this->phone->id, 'direction' => 'out', 'quantity' => 2, 'reason' => 'DAMAGED',
        ]);

        $this->returnItems($purchase, [['purchase_item_id' => $phoneLine->id, 'quantity' => 1]])->assertSessionHasErrors('quantity');

        // Rolled back completely.
        $this->assertSame(0, PurchaseReturn::count());
        $this->assertSame(0, $phoneLine->fresh()->returned_quantity);
        $this->assertSame('-21000.00', $this->supplier->fresh()->balance);
    }

    public function test_items_from_another_purchase_are_rejected()
    {
        $first = $this->purchase();
        $second = $this->purchase();

        $this->returnItems($first, [['purchase_item_id' => $second->items->first()->id, 'quantity' => 1]])
            ->assertSessionHasErrors('items.0.purchase_item_id');
    }

    public function test_return_on_a_paid_purchase_can_record_a_refund()
    {
        $purchase = $this->purchase(paid: '21000.00');
        $cableLine = $purchase->items->firstWhere('product_id', $this->cable->id);

        $this->returnItems($purchase, [['purchase_item_id' => $cableLine->id, 'quantity' => 2]], [
            'refund_amount' => '200.00', 'refund_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        $refund = Payment::where('purpose', 'SUPPLIER_REFUND')->sole();
        $this->assertSame('IN', $refund->direction->value);
        $this->assertSame('200.00', PurchaseReturn::sole()->refund_amount);
        $this->assertSame('0.00', $this->supplier->fresh()->balance);
        $this->assertSame(
            ['PURCHASE', 'PURCHASE_PAYMENT', 'PURCHASE_RETURN', 'SUPPLIER_REFUND'],
            $this->supplier->ledgerEntries()->orderBy('id')->get()->pluck('entry_type.value')->all(),
        );
    }

    public function test_refund_cannot_exceed_what_the_supplier_owes()
    {
        $purchase = $this->purchase(); // unpaid: the return only reduces the payable
        $cableLine = $purchase->items->firstWhere('product_id', $this->cable->id);

        $this->returnItems($purchase, [['purchase_item_id' => $cableLine->id, 'quantity' => 1]], [
            'refund_amount' => '100.00', 'refund_method' => 'CASH',
        ])->assertSessionHasErrors('refund_amount');

        $this->assertSame(0, PurchaseReturn::count());
    }

    public function test_return_validation_and_authorization()
    {
        $purchase = $this->purchase();
        $line = $purchase->items->first();

        $this->returnItems($purchase, [['purchase_item_id' => $line->id, 'quantity' => 0]])->assertSessionHasErrors('items');
        $this->returnItems($purchase, [['purchase_item_id' => $line->id, 'quantity' => 1]], ['reason' => ''])->assertSessionHasErrors('reason');
        $this->returnItems($purchase, [['purchase_item_id' => $line->id, 'quantity' => 1]], ['return_date' => today()->subYear()->toDateString()])
            ->assertSessionHasErrors('return_date');

        $this->actingAs($this->generalUser())->post("/purchases/{$purchase->id}/returns", [
            'return_date' => today()->toDateString(), 'reason' => 'x', 'items' => [['purchase_item_id' => $line->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->assertSame(0, PurchaseReturn::count());
    }

    public function test_all_invariants_hold_after_mixed_activity()
    {
        $purchase = $this->purchase(paid: '5000.00', discount: '100.00');
        $this->returnItems($purchase, [['purchase_item_id' => $purchase->items->last()->id, 'quantity' => 3]]);
        $this->actingAs($this->admin)->post('/supplier-payments', [
            'party_id' => $this->supplier->id, 'purchase_id' => $purchase->id, 'amount' => '1000.00', 'method' => 'CASH', 'date' => today()->toDateString(),
        ]);

        $this->artisan('ledger:reconcile')->assertSuccessful();
        $this->artisan('inventory:reconcile')->assertSuccessful();

        $purchase->refresh();
        $net = bcsub($purchase->total, $purchase->returned_amount, 2);
        $this->assertSame($purchase->due_amount, bcsub($net, $purchase->paid_amount, 2));
        $this->assertSame(bcsub('0', $purchase->due_amount, 2), $this->supplier->fresh()->balance);
    }
}
