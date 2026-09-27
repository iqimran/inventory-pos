<?php

namespace Tests\Feature\Sales;

use App\Domain\Inventory\StockService;
use App\Enums\PaymentStatus;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SaleReturnTest extends TestCase
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
        $this->cashier->givePermissionTo('returns.create');
        $this->customer = Party::factory()->customer()->create();
        $this->phone = Product::factory()->withStock(10)->create(['retail_price' => '10000.00', 'purchase_price' => '8000.00']);
        $this->case = Product::factory()->withStock(50)->create(['retail_price' => '300.00', 'purchase_price' => '100.00']);
    }

    /**
     * 1 phone (10000) + 4 cases (1200) = 11200 before discounts.
     */
    private function sale(string $paid, ?Party $customer = null, string $discount = '0'): Sale
    {
        $this->actingAs($this->cashier)->post('/sales', [
            'sale_type' => 'RETAIL',
            'party_id' => $customer?->id,
            'items' => [['product_id' => $this->phone->id, 'quantity' => 1], ['product_id' => $this->case->id, 'quantity' => 4]],
            'discount' => $discount,
            'paid_amount' => $paid,
            'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->first()->load('items');
    }

    private function line(Sale $sale, Product $product): int
    {
        return $sale->items->firstWhere('product_id', $product->id)->id;
    }

    private function returnItems(Sale $sale, array $items, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->cashier)->post("/sales/{$sale->id}/returns", array_merge([
            'reason' => 'Customer changed mind',
            'items' => $items,
        ], $overrides));
    }

    private function stock(Product $product): int
    {
        return app(StockService::class)->balance($product);
    }

    public function test_partial_return_on_a_due_sale_reduces_the_receivable_and_restocks()
    {
        $sale = $this->sale('0', $this->customer); // 11200 due

        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 2]])
            ->assertSessionHasNoErrors();

        $return = SaleReturn::sole();
        $this->assertMatchesRegularExpression('/^SRT-\d{6}-000001$/', $return->return_no);
        $this->assertSame('600.00', $return->subtotal);
        $this->assertSame('600.00', $return->adjustment_amount);
        $this->assertSame('0.00', $return->refund_amount);
        $this->assertSame('0.00', $return->credit_amount);
        $this->assertSame($this->cashier->id, $return->created_by);

        // Stock back in, through a movement referencing the return.
        $this->assertSame(48, $this->stock($this->case));
        $movement = StockMovement::where('type', 'SALE_RETURN_IN')->sole();
        $this->assertSame(2, $movement->quantity);
        $this->assertTrue($movement->reference->is($return));

        // Customer ledger credited; receivable and sale due reduced.
        $entry = $this->customer->ledgerEntries()->latest('id')->first();
        $this->assertSame('SALE_RETURN', $entry->entry_type->value);
        $this->assertSame('600.00', $entry->credit);
        $this->assertSame('10600.00', $this->customer->fresh()->balance);

        $sale->refresh();
        $this->assertSame('600.00', $sale->returned_amount);
        $this->assertSame('10600.00', $sale->due_amount);
        $this->assertSame(PaymentStatus::Due, $sale->payment_status);
    }

    public function test_full_return_of_a_due_sale_clears_it()
    {
        $sale = $this->sale('0', $this->customer);

        $this->returnItems($sale, [
            ['sale_item_id' => $this->line($sale, $this->phone), 'quantity' => 1],
            ['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 4],
        ])->assertSessionHasNoErrors();

        $this->assertSame('11200.00', SaleReturn::sole()->subtotal);
        $this->assertSame('0.00', $this->customer->fresh()->balance);
        $this->assertSame('0.00', $sale->fresh()->due_amount);
        $this->assertSame(PaymentStatus::Paid, $sale->fresh()->payment_status);
        $this->assertSame(10, $this->stock($this->phone));
        $this->assertSame(50, $this->stock($this->case));
    }

    public function test_excessive_return_is_rejected_including_across_returns()
    {
        $sale = $this->sale('0', $this->customer);
        $caseLine = $this->line($sale, $this->case);

        $this->returnItems($sale, [['sale_item_id' => $caseLine, 'quantity' => 5]])->assertSessionHasErrors('items.0.quantity');
        $this->returnItems($sale, [['sale_item_id' => $caseLine, 'quantity' => 3]])->assertSessionHasNoErrors();
        $this->returnItems($sale, [['sale_item_id' => $caseLine, 'quantity' => 2]])->assertSessionHasErrors('items.0.quantity');
        $this->returnItems($sale, [['sale_item_id' => $caseLine, 'quantity' => 1]])->assertSessionHasNoErrors();
        $this->returnItems($sale, [['sale_item_id' => $caseLine, 'quantity' => 1]])
            ->assertSessionHasErrors(['items.0.quantity' => 'This item has already been fully returned.']);

        $this->assertSame(2, SaleReturn::count());
        $this->assertSame(50, $this->stock($this->case));
        $this->assertSame('10000.00', $this->customer->fresh()->balance);
    }

    public function test_items_from_another_sale_are_rejected()
    {
        $first = $this->sale('0', $this->customer);
        $second = $this->sale('0', $this->customer);

        $this->returnItems($first, [['sale_item_id' => $this->line($second, $this->case), 'quantity' => 1]])
            ->assertSessionHasErrors('items.0.sale_item_id');
        $this->assertSame(0, SaleReturn::count());
    }

    public function test_return_of_a_paid_sale_can_be_refunded_in_cash()
    {
        $sale = $this->sale('11200.00', $this->customer);

        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 1]], [
            'refund_amount' => '300.00', 'refund_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        $return = SaleReturn::sole();
        $this->assertSame('0.00', $return->adjustment_amount);
        $this->assertSame('300.00', $return->refund_amount);
        $this->assertSame('0.00', $return->credit_amount);

        $refund = Payment::where('purpose', 'CUSTOMER_REFUND')->sole();
        $this->assertSame('OUT', $refund->direction->value);
        $this->assertTrue($refund->source->is($return));

        $this->assertSame(
            ['SALE', 'CUSTOMER_PAYMENT', 'SALE_RETURN', 'CUSTOMER_REFUND'],
            $this->customer->ledgerEntries()->orderBy('id')->get()->pluck('entry_type.value')->all(),
        );
        $this->assertSame('0.00', $this->customer->fresh()->balance);
        $this->assertSame(PaymentStatus::Paid, $sale->fresh()->payment_status);
    }

    public function test_return_of_a_paid_sale_can_be_kept_as_store_credit()
    {
        $sale = $this->sale('11200.00', $this->customer);

        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 2]], ['refund_amount' => '0'])
            ->assertSessionHasNoErrors();

        $return = SaleReturn::sole();
        $this->assertSame('600.00', $return->credit_amount);
        $this->assertSame(0, Payment::where('purpose', 'CUSTOMER_REFUND')->count());
        // Negative balance = the shop owes the customer (store credit).
        $this->assertSame('-600.00', $this->customer->fresh()->balance);
    }

    public function test_partially_paid_sale_splits_between_due_reduction_and_refund()
    {
        $sale = $this->sale('10500.00', $this->customer); // 700 due

        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->phone), 'quantity' => 1]], [
            'refund_amount' => '9300.00', 'refund_method' => 'BANK',
        ])->assertSessionHasNoErrors();

        $return = SaleReturn::sole();
        $this->assertSame('10000.00', $return->subtotal);
        $this->assertSame('700.00', $return->adjustment_amount);
        $this->assertSame('9300.00', $return->refund_amount);
        $this->assertSame('0.00', $return->credit_amount);
        $this->assertSame('0.00', $this->customer->fresh()->balance);
        $this->assertSame('0.00', $sale->fresh()->due_amount);
    }

    public function test_refund_cannot_exceed_what_was_paid_or_what_the_shop_owes()
    {
        $unpaid = $this->sale('0', $this->customer);
        $this->returnItems($unpaid, [['sale_item_id' => $this->line($unpaid, $this->case), 'quantity' => 1]], [
            'refund_amount' => '1.00', 'refund_method' => 'CASH',
        ])->assertSessionHasErrors('refund_amount');

        $paid = $this->sale('11200.00', $this->customer);
        $this->returnItems($paid, [['sale_item_id' => $this->line($paid, $this->case), 'quantity' => 1]], [
            'refund_amount' => '300.01', 'refund_method' => 'CASH',
        ])->assertSessionHasErrors('refund_amount');

        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(50 - 8, $this->stock($this->case));
    }

    public function test_refund_is_netted_against_other_debts_of_the_customer()
    {
        $this->sale('0', $this->customer); // another sale still owes 11200
        $paid = $this->sale('11200.00', $this->customer);

        // The shop does not owe this customer anything overall, so cash back is not allowed.
        $this->returnItems($paid, [['sale_item_id' => $this->line($paid, $this->case), 'quantity' => 1]], [
            'refund_amount' => '300.00', 'refund_method' => 'CASH',
        ])->assertSessionHasErrors('refund_amount');

        $this->returnItems($paid, [['sale_item_id' => $this->line($paid, $this->case), 'quantity' => 1]])->assertSessionHasNoErrors();
        $this->assertSame('300.00', SaleReturn::sole()->credit_amount);
        $this->assertSame('10900.00', $this->customer->fresh()->balance);
    }

    public function test_walk_in_return_is_refunded_in_full_without_ledger_entries()
    {
        $sale = $this->sale('11200.00');

        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 2]], [
            'refund_amount' => '100.00', 'refund_method' => 'CASH',
        ])->assertSessionHasErrors('refund_amount');

        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 2]], ['refund_method' => 'CASH'])
            ->assertSessionHasNoErrors();

        $return = SaleReturn::sole();
        $this->assertNull($return->party_id);
        $this->assertSame('600.00', $return->refund_amount);
        $this->assertNull(Payment::where('purpose', 'CUSTOMER_REFUND')->sole()->party_id);
        $this->assertSame(0, PartyLedgerEntry::count());
        $this->assertSame(48, $this->stock($this->case));
    }

    public function test_return_value_uses_discounted_price_and_never_drifts()
    {
        // Invoice discount 1120 (10%) is spread across lines: cases net 1080 for 4.
        $sale = $this->sale('0', $this->customer, '1120.00');
        $caseLine = $this->line($sale, $this->case);
        $this->assertSame('1080.00', $sale->items->firstWhere('id', $caseLine)->line_total);

        foreach (range(1, 3) as $i) {
            $this->returnItems($sale, [['sale_item_id' => $caseLine, 'quantity' => 1]])->assertSessionHasNoErrors();
        }
        $this->returnItems($sale, [['sale_item_id' => $caseLine, 'quantity' => 1]])->assertSessionHasNoErrors();

        $this->assertSame(['270.00', '270.00', '270.00', '270.00'], SaleReturn::orderBy('id')->pluck('subtotal')->all());
        $this->assertSame('1080.00', bcadd('0', (string) SaleReturn::sum('subtotal'), 2));
    }

    public function test_stock_comes_back_at_the_original_cost_snapshot()
    {
        $sale = $this->sale('11200.00', $this->customer);
        $snapshot = $sale->items->firstWhere('product_id', $this->phone->id)->unit_cost;

        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->phone), 'quantity' => 1]])->assertSessionHasNoErrors();

        $returnItem = SaleReturn::sole()->items()->sole();
        $this->assertSame($snapshot, $returnItem->unit_cost);
        $this->assertSame($snapshot, StockMovement::where('type', 'SALE_RETURN_IN')->sole()->unit_cost);
    }

    public function test_original_sale_document_is_never_modified()
    {
        $sale = $this->sale('5000.00', $this->customer);
        $before = $sale->only(['invoice_no', 'subtotal', 'items_discount', 'discount', 'total', 'sold_at', 'cost_total']);
        $itemsBefore = $sale->items->map->only(['quantity', 'unit_price', 'line_total', 'unit_cost', 'cost_total'])->all();

        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 2]]);

        $sale->refresh()->load('items');
        $this->assertEquals($before, $sale->only(array_keys($before)));
        $this->assertSame($itemsBefore, $sale->items->map->only(['quantity', 'unit_price', 'line_total', 'unit_cost', 'cost_total'])->all());
        $this->assertSame('5000.00', $sale->paid_amount);
    }

    public function test_return_is_atomic()
    {
        $sale = $this->sale('0', $this->customer);

        // A valid line plus an excessive one: nothing may be written.
        $this->returnItems($sale, [
            ['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 1],
            ['sale_item_id' => $this->line($sale, $this->phone), 'quantity' => 2],
        ])->assertSessionHasErrors('items.1.quantity');

        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(0, StockMovement::where('type', 'SALE_RETURN_IN')->count());
        $this->assertSame('11200.00', $this->customer->fresh()->balance);
        $this->assertSame(46, $this->stock($this->case));
    }

    public function test_validation_and_authorization()
    {
        $sale = $this->sale('0', $this->customer);
        $line = $this->line($sale, $this->case);

        $this->returnItems($sale, [['sale_item_id' => $line, 'quantity' => 0]])->assertSessionHasErrors('items');
        $this->returnItems($sale, [['sale_item_id' => $line, 'quantity' => 1]], ['reason' => ''])->assertSessionHasErrors('reason');
        $this->returnItems($sale, [['sale_item_id' => $line, 'quantity' => 1]], ['refund_amount' => '1', 'refund_method' => ''])
            ->assertSessionHasErrors('refund_method');

        $noReturns = $this->generalUser(); // returns.create is not a General User default
        $this->actingAs($noReturns)->post("/sales/{$sale->id}/returns", ['reason' => 'x', 'items' => [['sale_item_id' => $line, 'quantity' => 1]]])
            ->assertForbidden();
        $this->actingAs($noReturns)->get("/sales/{$sale->id}/returns/create")->assertForbidden();

        $this->assertSame(0, SaleReturn::count());
    }

    public function test_due_collection_respects_returns()
    {
        $sale = $this->sale('0', $this->customer);
        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->phone), 'quantity' => 1]]);

        $this->actingAs($this->cashier)->post('/customer-payments', [
            'party_id' => $this->customer->id, 'sale_id' => $sale->id, 'amount' => '1200.01', 'method' => 'CASH', 'date' => today()->toDateString(),
        ])->assertSessionHasErrors('amount');
        $this->actingAs($this->cashier)->post('/customer-payments', [
            'party_id' => $this->customer->id, 'sale_id' => $sale->id, 'amount' => '1200.00', 'method' => 'CASH', 'date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Paid, $sale->fresh()->payment_status);
        $this->assertSame('0.00', $this->customer->fresh()->balance);
    }

    public function test_all_invariants_hold_after_returns()
    {
        $sale = $this->sale('6000.00', $this->customer, '200.00');
        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->case), 'quantity' => 3]]);
        $this->returnItems($sale, [['sale_item_id' => $this->line($sale, $this->phone), 'quantity' => 1]], ['refund_amount' => '0']);

        $this->artisan('inventory:reconcile')->assertSuccessful();
        $this->artisan('ledger:reconcile')->assertSuccessful();

        // due = max(0, total − returned − paid)
        $sale->refresh();
        $expectedDue = Money::max('0.00', Money::sub(Money::sub($sale->total, $sale->returned_amount), $sale->paid_amount));
        $this->assertSame($expectedDue, $sale->due_amount);
        $this->assertSame(Money::of((string) SaleReturn::sum('subtotal')), $sale->returned_amount);
    }
}
