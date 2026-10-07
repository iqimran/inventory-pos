<?php

namespace Tests\Feature\Purchasing;

use App\Actions\Parties\SaveParty;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Enums\PaymentStatus;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SupplierAdvanceAndPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Party $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->supplier = Party::factory()->supplier()->create();
        $this->product = Product::factory()->create();
    }

    private function advance(string $amount): void
    {
        $this->actingAs($this->admin)->post('/supplier-advances', [
            'party_id' => $this->supplier->id, 'amount' => $amount, 'method' => 'BANK', 'date' => today()->toDateString(), 'reference_no' => 'TRX-9',
        ])->assertSessionHasNoErrors();
    }

    private function purchase(string $unitCost, int $quantity = 1, array $overrides = []): Purchase
    {
        $this->actingAs($this->admin)->post('/purchases', array_merge([
            'party_id' => $this->supplier->id,
            'purchase_date' => today()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
            'paid_amount' => '0',
            'payment_method' => 'CASH',
        ], $overrides))->assertSessionHasNoErrors();

        return Purchase::latest('id')->first();
    }

    private function pay(array $data): TestResponse
    {
        return $this->actingAs($this->admin)->post('/supplier-payments', array_merge([
            'party_id' => $this->supplier->id, 'method' => 'CASH', 'date' => today()->toDateString(),
        ], $data));
    }

    public function test_advance_can_exist_without_any_purchase()
    {
        $this->advance('5000.00');

        $payment = Payment::sole();
        $this->assertSame('SUPPLIER_ADVANCE', $payment->purpose->value);
        $this->assertSame('0.00', $payment->allocated_amount);
        $this->assertSame(0, Purchase::count());
        $this->assertSame('5000.00', $this->supplier->fresh()->balance);
        $this->assertSame('SUPPLIER_ADVANCE', $this->supplier->ledgerEntries()->sole()->entry_type->value);
        $this->assertSame('5000.00', app(PaymentAllocator::class)->availableAdvance($this->supplier->fresh()));
    }

    public function test_purchase_can_consume_an_advance_at_creation()
    {
        $this->advance('3000.00');

        $purchase = $this->purchase('5000.00', 1, ['apply_advance' => true, 'paid_amount' => '1000.00']);

        $this->assertSame('4000.00', $purchase->paid_amount);
        $this->assertSame('1000.00', $purchase->due_amount);
        $this->assertSame(PaymentStatus::Partial, $purchase->payment_status);
        $this->assertSame('3000.00', Payment::where('purpose', 'SUPPLIER_ADVANCE')->sole()->allocated_amount);
        // +3000 advance −5000 purchase +1000 paid = −1000 payable.
        $this->assertSame('-1000.00', $this->supplier->fresh()->balance);
        $this->assertSame('0.00', app(PaymentAllocator::class)->availableAdvance($this->supplier->fresh()));
    }

    public function test_advance_larger_than_purchase_leaves_remainder_available()
    {
        $this->advance('5000.00');

        $purchase = $this->purchase('2000.00', 1, ['apply_advance' => true]);

        $this->assertSame(PaymentStatus::Paid, $purchase->payment_status);
        $this->assertSame('3000.00', $this->supplier->fresh()->balance);
        $this->assertSame('3000.00', app(PaymentAllocator::class)->availableAdvance($this->supplier->fresh()));
    }

    public function test_advance_can_be_applied_to_an_existing_purchase_later()
    {
        $this->advance('500.00');
        $purchase = $this->purchase('1000.00'); // not applied at creation

        $this->assertSame('1000.00', $purchase->due_amount);

        $this->actingAs($this->admin)->post("/purchases/{$purchase->id}/apply-advance")->assertSessionHasNoErrors();

        $purchase->refresh();
        $this->assertSame('500.00', $purchase->paid_amount);
        $this->assertSame('500.00', $purchase->due_amount);
        // No new ledger entry: the advance was already posted.
        $this->assertSame(2, $this->supplier->ledgerEntries()->count());
        $this->assertSame('-500.00', $this->supplier->fresh()->balance);

        $this->actingAs($this->admin)->post("/purchases/{$purchase->id}/apply-advance")->assertSessionHasErrors('advance');
    }

    public function test_payment_against_a_specific_purchase_supports_partial_settlement()
    {
        $purchase = $this->purchase('1000.00');

        $this->pay(['purchase_id' => $purchase->id, 'amount' => '300.00'])->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Partial, $purchase->fresh()->payment_status);
        $this->assertSame('700.00', $purchase->fresh()->due_amount);

        $this->pay(['purchase_id' => $purchase->id, 'amount' => '700.00'])->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Paid, $purchase->fresh()->payment_status);
        $this->assertSame('0.00', $this->supplier->fresh()->balance);

        $payment = Payment::latest('id')->first();
        $this->assertMatchesRegularExpression('/^PAY-\d{6}-\d{6}$/', $payment->payment_no);
        $this->actingAs($this->admin)->get("/supplier-payments/{$payment->id}")->assertOk();
    }

    public function test_payment_cannot_exceed_the_purchase_due()
    {
        $purchase = $this->purchase('1000.00');

        $this->pay(['purchase_id' => $purchase->id, 'amount' => '1000.01'])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());
    }

    public function test_on_account_payment_settles_oldest_purchases_first()
    {
        $first = $this->purchase('400.00', 1, ['purchase_date' => today()->subDays(5)->toDateString()]);
        $second = $this->purchase('600.00', 1, ['purchase_date' => today()->subDay()->toDateString()]);

        $this->pay(['amount' => '700.00'])->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Paid, $first->fresh()->payment_status);
        $this->assertSame('300.00', $second->fresh()->due_amount);
        $this->assertSame(PaymentStatus::Partial, $second->fresh()->payment_status);
        $this->assertSame('700.00', Payment::sole()->allocated_amount);
        $this->assertSame('-300.00', $this->supplier->fresh()->balance);
    }

    public function test_on_account_payment_cannot_exceed_the_payable_balance()
    {
        $this->purchase('400.00');

        $this->pay(['amount' => '400.01'])->assertSessionHasErrors('amount');
    }

    public function test_payment_can_settle_an_opening_payable_without_creating_an_advance()
    {
        $supplier = app(SaveParty::class)->handle(null, [
            'name' => 'Legacy Supplier', 'type' => 'SUPPLIER', 'is_active' => true,
            'opening_balance' => '1000.00', 'opening_balance_type' => 'PAYABLE',
        ]);

        $this->actingAs($this->admin)->post('/supplier-payments', [
            'party_id' => $supplier->id, 'amount' => '1000.00', 'method' => 'CASH', 'date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $supplier->fresh()->balance);
        // The unallocated payment settled the opening balance; it is not an advance.
        $this->assertSame('0.00', app(PaymentAllocator::class)->availableAdvance($supplier->fresh()));
    }

    private function supplierHoldingOpeningAdvance(string $amount): Party
    {
        return $this->supplier = app(SaveParty::class)->handle(null, [
            'name' => 'Legacy Supplier', 'type' => 'SUPPLIER', 'is_active' => true,
            'opening_balance' => $amount, 'opening_balance_type' => 'RECEIVABLE',
        ]);
    }

    public function test_opening_receivable_and_later_advance_can_both_settle_a_purchase()
    {
        $this->supplierHoldingOpeningAdvance('50000.00');
        $this->advance('5000.00');

        $this->assertSame('55000.00', app(PaymentAllocator::class)->availableAdvance($this->supplier->fresh()));

        $purchase = $this->purchase('8000.00', 1, ['apply_advance' => true]);

        $this->assertSame('8000.00', $purchase->paid_amount);
        $this->assertSame(PaymentStatus::Paid, $purchase->payment_status);
        // The opening advance (oldest) is consumed first; no payment backs that allocation.
        $this->assertSame('8000.00', Money::of((string) $purchase->allocations()->whereNull('payment_id')->sum('amount')));
        $this->assertSame('0.00', Payment::where('purpose', 'SUPPLIER_ADVANCE')->sole()->allocated_amount);
        $this->assertSame('47000.00', $this->supplier->fresh()->balance);
        $this->assertSame('47000.00', app(PaymentAllocator::class)->availableAdvance($this->supplier->fresh()));

        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}")->assertOk();
    }

    public function test_opening_advance_can_settle_the_rest_of_an_existing_purchase()
    {
        $this->supplierHoldingOpeningAdvance('50000.00');
        $this->advance('5000.00');
        $purchase = $this->purchase('8000.00');

        $this->actingAs($this->admin)->post("/purchases/{$purchase->id}/apply-advance")->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Paid, $purchase->fresh()->payment_status);
        $this->assertSame('47000.00', app(PaymentAllocator::class)->availableAdvance($this->supplier->fresh()));
    }

    public function test_opening_advance_is_capped_once_consumed()
    {
        $this->supplierHoldingOpeningAdvance('1000.00');

        $purchase = $this->purchase('1500.00', 1, ['apply_advance' => true]);

        $this->assertSame('1000.00', $purchase->paid_amount);
        $this->assertSame('500.00', $purchase->due_amount);
        $this->assertSame('0.00', app(PaymentAllocator::class)->availableAdvance($this->supplier->fresh()));

        $second = $this->purchase('100.00', 1, ['apply_advance' => true]);
        $this->assertSame('0.00', $second->paid_amount);
    }

    public function test_customer_opening_receivable_is_not_a_supplier_advance()
    {
        $customer = app(SaveParty::class)->handle(null, [
            'name' => 'Walk-in Debtor', 'type' => 'CUSTOMER', 'is_active' => true,
            'opening_balance' => '1000.00', 'opening_balance_type' => 'RECEIVABLE',
        ]);

        $this->assertSame('0.00', app(PaymentAllocator::class)->availableAdvance($customer));
    }

    public function test_payments_require_permission()
    {
        $user = $this->generalUser();
        $purchase = $this->purchase('100.00');

        $this->actingAs($user)->post('/supplier-payments', [
            'party_id' => $this->supplier->id, 'purchase_id' => $purchase->id, 'amount' => '10', 'method' => 'CASH', 'date' => today()->toDateString(),
        ])->assertForbidden();
        $this->actingAs($user)->post('/supplier-advances', [
            'party_id' => $this->supplier->id, 'amount' => '10', 'method' => 'CASH', 'date' => today()->toDateString(),
        ])->assertForbidden();
        $this->actingAs($user)->post("/purchases/{$purchase->id}/apply-advance")->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    public function test_payment_validation()
    {
        $other = Party::factory()->supplier()->create();
        $otherPurchase = (function () use ($other) {
            $this->actingAs($this->admin)->post('/purchases', [
                'party_id' => $other->id, 'purchase_date' => today()->toDateString(),
                'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => '50']],
            ]);

            return Purchase::sole();
        })();

        $this->pay(['purchase_id' => $otherPurchase->id, 'amount' => '10'])->assertSessionHasErrors('purchase_id');
        $this->pay(['amount' => '0'])->assertSessionHasErrors('amount');
        $this->pay(['amount' => '10', 'method' => 'GOLD'])->assertSessionHasErrors('method');
        $this->pay(['amount' => '10', 'date' => today()->addDay()->toDateString()])->assertSessionHasErrors('date');
    }
}
