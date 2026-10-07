<?php

namespace Tests\Feature\Critical;

use App\Domain\Inventory\StockService;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Models\Device;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * T050 — critical end-to-end workflow: one business day driven only through the HTTP endpoints,
 * as the UI does, with the stock and ledger invariants asserted after every step:
 *
 *   purchase → stock IN + supplier ledger → supplier advance / purchase return
 *   → POS sale → stock OUT + customer ledger → sale return → due collection
 *   → service job → parts → stock OUT → service charge → combined invoice
 *   → revenue: PRODUCT + SERVICE = COMBINED
 */
#[Group('critical')]
class CriticalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Party $supplier;

    private Party $customer;

    private Product $ic;

    private Product $connector;

    private Product $case;

    protected function setUp(): void
    {
        parent::setUp();

        config(['reports.timezone' => 'UTC']);
        $this->travelTo('2026-09-15 09:00:00');
        $this->owner = $this->admin();
        $this->cashier = $this->generalUser();
        $this->supplier = Party::factory()->supplier()->create(['name' => 'Parts Wholesale']);
        $this->customer = Party::factory()->customer()->create(['name' => 'Rahim']);
        $this->ic = Product::factory()->create(['name' => 'IC', 'barcode' => '200000000001', 'retail_price' => '800.00', 'wholesale_price' => '700.00']);
        $this->connector = Product::factory()->create(['name' => 'Connector', 'barcode' => '200000000002', 'retail_price' => '200.00', 'wholesale_price' => '150.00']);
        $this->case = Product::factory()->create(['name' => 'Case', 'barcode' => '200000000003', 'retail_price' => '300.00', 'wholesale_price' => '220.00']);
    }

    /**
     * Stock equals its movement ledger for every product; every party balance equals its ledger;
     * payment allocations match their cached totals; and the reconciliation commands agree.
     */
    private function assertInvariants(string $step): void
    {
        $stock = app(StockService::class);
        foreach ([$this->ic, $this->connector, $this->case] as $product) {
            $this->assertSame($stock->ledgerBalance($product), $stock->balance($product), "{$step}: stock of {$product->name} drifted from its ledger");
            $this->assertGreaterThanOrEqual(0, $stock->balance($product), "{$step}: negative stock");
        }

        foreach ([$this->supplier, $this->customer] as $party) {
            $this->assertSame(app(PartyLedgerService::class)->ledgerBalance($party), $party->fresh()->balance, "{$step}: balance of {$party->name} drifted from its ledger");
        }

        $this->artisan('inventory:reconcile')->assertSuccessful();
        $this->artisan('ledger:reconcile')->assertSuccessful();
    }

    private function stock(Product $product): int
    {
        return app(StockService::class)->balance($product);
    }

    public function test_a_full_business_day_keeps_stock_ledgers_and_revenue_consistent()
    {
        // ── 1. Purchases: cash, partial and due → stock IN + supplier payable ────────────────
        $this->actingAs($this->owner);
        $purchase = function (array $items, string $paid) {
            $this->post('/purchases', [
                'party_id' => $this->supplier->id, 'purchase_date' => '2026-09-15', 'paid_amount' => $paid, 'payment_method' => 'CASH',
                'items' => array_map(fn ($i) => ['product_id' => $i[0]->id, 'quantity' => $i[1], 'unit_cost' => $i[2]], $items),
            ])->assertSessionHasNoErrors();

            return Purchase::latest('id')->first();
        };

        $cash = $purchase([[$this->ic, 10, '500.00']], '5000.00');                   // cash: due 0
        $partial = $purchase([[$this->connector, 20, '80.00']], '1000.00');          // partial: due 600
        $due = $purchase([[$this->case, 30, '150.00']], '0');                        // due: 4500

        $this->assertSame(['PAID', '0.00'], [$cash->payment_status->value, $cash->due_amount]);
        $this->assertSame(['PARTIAL', '600.00'], [$partial->payment_status->value, $partial->due_amount]);
        $this->assertSame(['DUE', '4500.00'], [$due->payment_status->value, $due->due_amount]);
        $this->assertSame([10, 20, 30], [$this->stock($this->ic), $this->stock($this->connector), $this->stock($this->case)]);
        $this->assertSame('-5100.00', $this->supplier->fresh()->balance);            // shop owes 600 + 4500
        $this->assertInvariants('purchases');

        // ── 2. Supplier advance without a purchase, then reconciled against a new purchase ──────
        $this->post('/supplier-advances', ['party_id' => $this->supplier->id, 'amount' => '6000.00', 'method' => 'BANK', 'date' => '2026-09-15'])
            ->assertSessionHasNoErrors();
        $this->assertSame('900.00', $this->supplier->fresh()->balance);              // 6000 paid against 5100 owed: 900 advance
        $this->post('/purchases', [
            'party_id' => $this->supplier->id, 'purchase_date' => '2026-09-15', 'paid_amount' => '0', 'apply_advance' => true,
            'items' => [['product_id' => $this->ic->id, 'quantity' => 1, 'unit_cost' => '500.00']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('PAID', Purchase::latest('id')->first()->payment_status->value); // 500 settled from the advance
        $this->assertSame('400.00', $this->supplier->fresh()->balance);
        $this->assertSame(11, $this->stock($this->ic));
        $this->assertInvariants('supplier advance');

        // ── 3. Purchase return → stock OUT + supplier credit ────────────────────────────────────
        $this->post("/purchases/{$due->id}/returns", [
            'return_date' => '2026-09-15', 'reason' => 'Damaged box', 'items' => [['purchase_item_id' => $due->items()->sole()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(28, $this->stock($this->case));
        $this->assertSame('700.00', $this->supplier->fresh()->balance);              // 400 advance + 2 × 150 returned
        $this->assertInvariants('purchase return');

        // ── 4. Stock adjustments are restricted to permitted users ───────────────────────────────
        $this->actingAs($this->cashier)->post('/inventory/adjustments', ['product_id' => $this->case->id, 'direction' => 'out', 'quantity' => 1, 'reason' => 'DAMAGED'])
            ->assertForbidden();
        $this->assertSame(28, $this->stock($this->case));

        // ── 5. POS: barcode lookup, retail cash sale, wholesale sale on due ──────────────────────
        $this->travelTo('2026-09-15 11:00:00');
        $this->actingAs($this->cashier)->getJson('/pos/products/lookup/200000000003')->assertOk()->assertJsonPath('data.name', 'Case');

        $this->post('/sales', [
            'sale_type' => 'RETAIL', 'items' => [['product_id' => $this->case->id, 'quantity' => 2]], 'paid_amount' => '600.00', 'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();
        $retail = Sale::latest('id')->first();
        $this->get("/sales/{$retail->id}/receipt")->assertOk()->assertInertia(fn (Assert $page) => $page->where('sale.data.total', '600.00'));

        $this->post('/sales', [
            'sale_type' => 'WHOLESALE', 'party_id' => $this->customer->id, 'items' => [['product_id' => $this->case->id, 'quantity' => 10]],
            'paid_amount' => '1000.00', 'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();
        $wholesale = Sale::latest('id')->first();
        $this->assertSame(['2200.00', '1200.00', 'PARTIAL'], [$wholesale->total, $wholesale->due_amount, $wholesale->payment_status->value]);
        $this->assertSame(16, $this->stock($this->case));
        $this->assertSame('1200.00', $this->customer->fresh()->balance);
        $this->assertInvariants('sales');

        // ── 6. Sale return: cannot exceed eligible quantity; stock IN; ledger credit ────────────
        $item = $wholesale->items()->sole()->id;
        // Returns need returns.create, which the default cashier role does not have.
        $this->post("/sales/{$wholesale->id}/returns", ['reason' => 'Too many', 'items' => [['sale_item_id' => $item, 'quantity' => 1]]])
            ->assertForbidden();

        $this->actingAs($this->owner);
        $this->post("/sales/{$wholesale->id}/returns", ['reason' => 'Too many', 'items' => [['sale_item_id' => $item, 'quantity' => 11]]])
            ->assertSessionHasErrors();
        $this->post("/sales/{$wholesale->id}/returns", ['reason' => 'Too many', 'items' => [['sale_item_id' => $item, 'quantity' => 2]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(18, $this->stock($this->case));
        $this->assertSame('760.00', $this->customer->fresh()->balance);              // 1200 − 2 × 220
        $this->assertSame('760.00', $wholesale->fresh()->due_amount);
        $this->assertInvariants('sale return');

        // ── 7. Due collection ────────────────────────────────────────────────────────────────────
        $this->post('/customer-payments', [
            'party_id' => $this->customer->id, 'sale_id' => $wholesale->id, 'amount' => '760.00', 'method' => 'CASH', 'date' => '2026-09-15',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['0.00', 'PAID'], [$wholesale->fresh()->due_amount, $wholesale->fresh()->payment_status->value]);
        $this->assertSame('0.00', $this->customer->fresh()->balance);
        $this->assertInvariants('due collection');

        // ── 8. Mobile service: draft parts do not move stock; invoicing consumes them ────────────
        $this->travelTo('2026-09-15 15:00:00');
        $device = Device::factory()->create(['party_id' => $this->customer->id]);
        $this->post('/service/jobs', ['party_id' => $this->customer->id, 'device_id' => $device->id, 'complaint' => 'No charging'])->assertSessionHasNoErrors();
        $job = ServiceJob::sole();
        $this->post("/service/jobs/{$job->id}/parts", ['product_id' => $this->ic->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post("/service/jobs/{$job->id}/parts", ['product_id' => $this->connector->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post("/service/jobs/{$job->id}/charges", ['description' => 'Repair', 'amount' => '500'])->assertSessionHasNoErrors();
        $this->assertSame([11, 20], [$this->stock($this->ic), $this->stock($this->connector)]); // draft: untouched

        foreach ([['DIAGNOSING'], ['WAITING_FOR_APPROVAL', ['diagnosis' => 'IC and connector']], ['IN_PROGRESS', ['approved_amount' => '1500']], ['READY']] as $step) {
            $this->post("/service/jobs/{$job->id}/status", ['status' => $step[0], ...($step[1] ?? [])])->assertSessionHasNoErrors();
        }

        $this->post("/service/jobs/{$job->id}/invoice", ['paid_amount' => '1000', 'payment_method' => 'CASH', 'deliver' => true])->assertSessionHasNoErrors();
        $invoice = ServiceInvoice::sole();
        $this->assertSame(['1500.00', '1000.00', '500.00'], [$invoice->total, $invoice->product_total, $invoice->service_total]);
        $this->assertSame([10, 19], [$this->stock($this->ic), $this->stock($this->connector)]); // parts consumed
        $this->assertSame(2, StockMovement::where('type', 'SERVICE_PART_OUT')->count());      // labour never moves stock
        $this->assertSame('DELIVERED', $job->fresh()->status->value);
        $this->assertSame('500.00', $this->customer->fresh()->balance);              // 1500 − 1000 paid
        $this->assertInvariants('service invoice');

        // ── 9. Revenue: PRODUCT + SERVICE = COMBINED, and it matches document totals ─────────────
        $this->actingAs($this->owner)->get('/reports/revenue?from=2026-09-15&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Product: POS 600 + 2200 − return 440, plus parts 1000 = 3360. Service: 500. Combined: 3860.
                ->where('totals.product', '3360.00')
                ->where('totals.service', '500.00')
                ->where('totals.combined', '3860.00')
                ->where('totals.documents.total', '3860.00')
                ->where('totals.documents.matches', true));

        $this->get('/dashboard?from=2026-09-15&to=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.combined_revenue', '3860.00')
                // Customer owes 500; the supplier holds 700 of the shop's money (advance + returned goods).
                ->where('summary.outstanding.customer_receivable', '500.00')
                ->where('summary.outstanding.supplier_advance', '700.00')
                ->where('summary.outstanding.supplier_payable', '0.00'));

        // Stock report closing balances equal current stock.
        $this->get('/reports/stock?from=2026-09-01&to=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('movements.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['closing'] === $this->stock(Product::find($row['product_id'])))));

        // Nothing financial was ever deleted.
        $this->assertSame(0, DB::table('sales')->count() - Sale::count());
        $this->assertInvariants('end of day');
    }
}
