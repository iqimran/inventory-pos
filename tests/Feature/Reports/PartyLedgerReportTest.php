<?php

namespace Tests\Feature\Reports;

use App\Actions\Parties\SaveParty;
use App\Actions\Sales\CollectSalePayment;
use App\Actions\Sales\CreateSaleReturn;
use App\Enums\PartyType;
use App\Enums\PaymentMethod;
use App\Models\Party;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T045 — outstanding balances and per-party ledger summary.
 */
class PartyLedgerReportTest extends ReportTestCase
{
    public function test_opening_transactions_payments_returns_and_closing()
    {
        $case = $this->product('Case', '100.00');

        $this->travelTo('2026-08-10 10:00:00');
        $this->sale([[$case, 3]]);                                      // before the period: opening 300

        $this->travelTo('2026-09-10 10:00:00');
        $sale = $this->sale([[$case, 5]]);                              // +500
        $this->serviceInvoice([], '250');                               // +250
        app(CollectSalePayment::class)->handle($this->customer, '400.00', PaymentMethod::Cash, '2026-09-11');   // −400
        app(CreateSaleReturn::class)->handle($sale, ['items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1]], 'reason' => 'x']); // −100

        $this->travelTo('2026-10-01 10:00:00');
        $this->sale([[$case, 1]]);                                      // after the period

        $this->get('/reports/parties?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/parties')
                ->where('parties.total', 1)
                ->where('parties.data.0.name', 'Rahim')
                ->where('parties.data.0.opening', '300.00')
                ->where('parties.data.0.transactions', '750.00')
                ->where('parties.data.0.payments', '-400.00')
                ->where('parties.data.0.returns', '-100.00')
                ->where('parties.data.0.adjustments', '0.00')
                ->where('parties.data.0.closing', '550.00')
                // Outstanding is the current balance (includes October's sale).
                ->where('outstanding.receivable', '650.00')
                ->where('outstanding.receivable_parties', 1));

        $this->assertSame('650.00', $this->customer->fresh()->balance);
    }

    public function test_receivables_payables_and_filters()
    {
        $supplier = app(SaveParty::class)->handle(null, [
            'name' => 'Gadget Wholesale', 'type' => PartyType::Supplier, 'phone' => '01800000000',
            'opening_balance' => '1200.00', 'opening_balance_type' => 'PAYABLE', 'is_active' => true,
        ]);
        $case = $this->product('Case', '100.00');
        $this->sale([[$case, 2]]);
        Party::factory()->customer()->create(['name' => 'Settled customer']); // no activity: omitted

        $this->get('/reports/parties?from=2026-09-01&to=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('outstanding', ['receivable' => '200.00', 'receivable_parties' => 1, 'payable' => '1200.00', 'payable_parties' => 1])
                ->where('parties.total', 2));

        $this->get('/reports/parties?from=2026-09-01&to=2026-09-30&side=payable')
            ->assertInertia(fn (Assert $page) => $page
                ->where('parties.total', 1)
                ->where('parties.data.0.id', $supplier->id)
                ->where('parties.data.0.adjustments', '-1200.00')   // opening balance entry
                ->where('parties.data.0.closing', '-1200.00'));

        $this->get('/reports/parties?type=CUSTOMER&from=2026-09-01&to=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page->where('parties.total', 1)->where('outstanding.payable', '0.00'));
        $this->get('/reports/parties?q=0180&from=2026-09-01&to=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page->where('parties.total', 1)->where('parties.data.0.name', 'Gadget Wholesale'));
    }
}
