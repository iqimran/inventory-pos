<?php

namespace Tests\Feature\Sales;

use App\Actions\Parties\SaveParty;
use App\Enums\PaymentStatus;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerDueTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Party $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->generalUser(); // has sales.collect by default
        $this->customer = Party::factory()->customer()->create();
        $this->product = Product::factory()->withStock(100)->create(['retail_price' => '1000.00']);
    }

    private function dueSale(int $quantity, string $paid = '0'): Sale
    {
        $this->actingAs($this->cashier)->post('/sales', [
            'sale_type' => 'RETAIL',
            'party_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
            'paid_amount' => $paid,
            'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->first();
    }

    private function collect(array $data): TestResponse
    {
        return $this->actingAs($this->cashier)->post('/customer-payments', array_merge([
            'party_id' => $this->customer->id, 'method' => 'CASH', 'date' => today()->toDateString(),
        ], $data));
    }

    public function test_collect_against_a_specific_sale_in_parts()
    {
        $sale = $this->dueSale(2); // 2000 due

        $this->collect(['sale_id' => $sale->id, 'amount' => '500.00'])->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Partial, $sale->fresh()->payment_status);
        $this->assertSame('1500.00', $sale->fresh()->due_amount);

        $this->collect(['sale_id' => $sale->id, 'amount' => '1500.00'])->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Paid, $sale->fresh()->payment_status);
        $this->assertSame('0.00', $this->customer->fresh()->balance);

        $payment = Payment::latest('id')->first();
        $this->actingAs($this->cashier)->get("/customer-payments/{$payment->id}")
            ->assertInertia(fn ($page) => $page->component('customer-payments/show')->where('payment.data.allocations.0.document.number', $sale->invoice_no));
    }

    public function test_on_account_collection_settles_oldest_sales_first()
    {
        $first = $this->dueSale(1);
        $second = $this->dueSale(2);

        $this->collect(['amount' => '1500.00'])->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Paid, $first->fresh()->payment_status);
        $this->assertSame('1500.00', $second->fresh()->due_amount);
        $this->assertSame('1500.00', $this->customer->fresh()->balance);
    }

    public function test_collection_cannot_exceed_due_or_receivable()
    {
        $sale = $this->dueSale(1);

        $this->collect(['sale_id' => $sale->id, 'amount' => '1000.01'])->assertSessionHasErrors('amount');
        $this->collect(['amount' => '1000.01'])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());
    }

    public function test_collection_can_settle_an_opening_receivable()
    {
        $customer = app(SaveParty::class)->handle(null, [
            'name' => 'Old Customer', 'type' => 'CUSTOMER', 'is_active' => true, 'opening_balance' => '750', 'opening_balance_type' => 'RECEIVABLE',
        ]);

        $this->actingAs($this->cashier)->post('/customer-payments', [
            'party_id' => $customer->id, 'amount' => '750', 'method' => 'MOBILE_BANKING', 'date' => today()->toDateString(), 'reference_no' => 'BK123',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $customer->fresh()->balance);
    }

    public function test_sale_from_another_customer_is_rejected()
    {
        $other = Party::factory()->customer()->create();
        $sale = $this->dueSale(1);

        $this->actingAs($this->cashier)->post('/customer-payments', [
            'party_id' => $other->id, 'sale_id' => $sale->id, 'amount' => '10', 'method' => 'CASH', 'date' => today()->toDateString(),
        ])->assertSessionHasErrors('sale_id');
    }

    public function test_collection_requires_permission()
    {
        $sale = $this->dueSale(1);

        $this->actingAs(User::factory()->create())->post('/customer-payments', [
            'party_id' => $this->customer->id, 'sale_id' => $sale->id, 'amount' => '10', 'method' => 'CASH', 'date' => today()->toDateString(),
        ])->assertForbidden();
    }

    public function test_ledger_statement_shows_sale_and_payments()
    {
        $this->dueSale(3, '1000.00');
        $this->collect(['amount' => '500.00']);

        $types = $this->customer->ledgerEntries()->orderBy('id')->get()->pluck('entry_type.value')->all();
        $this->assertSame(['SALE', 'CUSTOMER_PAYMENT', 'CUSTOMER_PAYMENT'], $types);
        $this->assertSame('1500.00', $this->customer->fresh()->balance);
        $this->artisan('ledger:reconcile')->assertSuccessful();
    }
}
