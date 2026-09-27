<?php

namespace Tests\Feature\Purchasing;

use App\Actions\Parties\SaveParty;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchasingPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Party $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->supplier = Party::factory()->supplier()->create(['name' => 'Rahim Traders']);
    }

    private function purchase(string $paid = '0'): Purchase
    {
        $this->actingAs($this->admin)->post('/purchases', [
            'party_id' => $this->supplier->id,
            'purchase_date' => today()->toDateString(),
            'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 2, 'unit_cost' => '500.00']],
            'paid_amount' => $paid,
            'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        return Purchase::latest('id')->first();
    }

    public function test_party_pages_render()
    {
        $this->purchase('300.00');

        $this->actingAs($this->admin)->get('/parties?balance=payable')
            ->assertInertia(fn (Assert $page) => $page->component('parties/index')->has('parties.data', 1)->where('parties.data.0.balance', '-700.00'));
        $this->actingAs($this->admin)->get('/parties/create')->assertInertia(fn (Assert $page) => $page->component('parties/create')->has('types', 3));
        $this->actingAs($this->admin)->get("/parties/{$this->supplier->id}/edit")->assertInertia(fn (Assert $page) => $page->component('parties/edit'));
        $this->actingAs($this->admin)->get("/parties/{$this->supplier->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('parties/show')
                ->has('statement.entries', 2)
                ->where('statement.closing_balance', '-700.00')
                ->where('summary.due_purchases_count', 1)
                ->where('summary.due_purchases_amount', '700.00'));
    }

    public function test_party_filters_by_type_and_search()
    {
        Party::factory()->customer()->create(['name' => 'Walk-in Karim']);
        Party::factory()->both()->create(['name' => 'Dual Dealer']);

        $this->actingAs($this->admin)->get('/parties?type=SUPPLIER')
            ->assertInertia(fn (Assert $page) => $page->has('parties.data', 2));
        $this->actingAs($this->admin)->get('/parties?search=karim')
            ->assertInertia(fn (Assert $page) => $page->has('parties.data', 1)->where('parties.data.0.name', 'Walk-in Karim'));
    }

    public function test_purchase_pages_render_without_n_plus_one()
    {
        foreach (range(1, 6) as $i) {
            $this->purchase();
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/purchases')
            ->assertInertia(fn (Assert $page) => $page->component('purchases/index')->has('purchases.data', 6));
        $this->assertLessThan(20, count(DB::getQueryLog()));

        $purchase = Purchase::first();
        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}")
            ->assertInertia(fn (Assert $page) => $page->component('purchases/show')->has('purchase.data.items', 1)->where('applicableAdvance', '0.00'));
        $this->actingAs($this->admin)->get("/purchases?status=DUE&party_id={$this->supplier->id}")
            ->assertInertia(fn (Assert $page) => $page->has('purchases.data', 6));
        $this->actingAs($this->admin)->get("/purchases?q={$purchase->purchase_no}")
            ->assertInertia(fn (Assert $page) => $page->has('purchases.data', 1));
    }

    public function test_purchase_create_page_provides_supplier_advance_and_product_search()
    {
        $this->actingAs($this->admin)->post('/supplier-advances', [
            'party_id' => $this->supplier->id, 'amount' => '250.00', 'method' => 'CASH', 'date' => today()->toDateString(),
        ]);
        Product::factory()->create(['name' => 'Galaxy Charger']);
        Party::factory()->customer()->create();

        $this->actingAs($this->admin)->get("/purchases/create?party_id={$this->supplier->id}&q=galaxy")
            ->assertInertia(fn (Assert $page) => $page
                ->component('purchases/create')
                ->has('suppliers', 1)
                ->where('supplier.available_advance', '250.00')
                ->where('results.data.0.name', 'Galaxy Charger'));
    }

    public function test_return_and_payment_pages_render()
    {
        $purchase = $this->purchase('200.00');

        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}/returns/create")
            ->assertInertia(fn (Assert $page) => $page->component('purchases/returns/create')->where('purchase.data.items.0.returnable_quantity', 2));
        $this->actingAs($this->admin)->get('/purchase-returns')->assertInertia(fn (Assert $page) => $page->component('purchases/returns/index'));

        $this->actingAs($this->admin)->get("/supplier-payments/create?party_id={$this->supplier->id}&purchase_id={$purchase->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier-payments/create')
                ->where('mode', 'payment')
                ->where('supplier.payable', '800.00')
                ->has('supplier.due_purchases', 1));
        $this->actingAs($this->admin)->get('/supplier-payments/create?mode=advance')
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'advance'));
        $this->actingAs($this->admin)->get('/supplier-payments')
            ->assertInertia(fn (Assert $page) => $page->component('supplier-payments/index')->has('payments.data', 1));

        $payment = Payment::sole();
        $this->actingAs($this->admin)->get("/supplier-payments/{$payment->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier-payments/show')
                ->where('payment.data.allocations.0.document.number', $purchase->purchase_no));
    }

    public function test_opening_balance_party_created_via_action_shows_in_statement()
    {
        $party = app(SaveParty::class)->handle(null, [
            'name' => 'Old Supplier', 'type' => 'SUPPLIER', 'is_active' => true, 'opening_balance' => '1500', 'opening_balance_type' => 'PAYABLE',
        ]);

        $this->actingAs($this->admin)->get("/parties/{$party->id}")
            ->assertInertia(fn (Assert $page) => $page->where('statement.entries.0.entry_type', 'OPENING_BALANCE'));
    }

    public function test_access_is_permission_based()
    {
        $purchase = $this->purchase();
        $viewer = $this->generalUser(); // has parties.view only (no purchases.*)
        $nobody = User::factory()->create();

        $this->actingAs($viewer)->get('/parties')->assertOk();
        $this->actingAs($viewer)->get('/purchases')->assertForbidden();
        $this->actingAs($viewer)->get("/purchases/{$purchase->id}")->assertForbidden();
        $this->actingAs($viewer)->get('/purchases/create')->assertForbidden();
        $this->actingAs($viewer)->post('/purchases', [])->assertForbidden();
        $this->actingAs($viewer)->get("/purchases/{$purchase->id}/returns/create")->assertForbidden();
        $this->actingAs($viewer)->get('/supplier-payments')->assertForbidden();
        $this->actingAs($viewer)->get('/supplier-payments/create')->assertForbidden();

        $this->actingAs($nobody)->get('/parties')->assertForbidden();
        $this->actingAs($nobody)->get("/parties/{$this->supplier->id}")->assertForbidden();
    }
}
