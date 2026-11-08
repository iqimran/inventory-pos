<?php

namespace Tests\Feature\Purchasing;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PartyStatement;
use App\Enums\LedgerEntryType;
use App\Models\Party;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Long statements are paginated without breaking the running balance.
 */
class PartyStatementPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->party = Party::factory()->customer()->create();
        $ledger = app(PartyLedgerService::class);
        $day = CarbonImmutable::parse('2026-01-01 10:00:00');

        $ledger->debit($this->party, LedgerEntryType::Sale, '1000.00', null, 'Before the period', $day->subDay());   // opening 1000

        // 120 entries in the period: debit 100 on odd, credit 30 on even.
        foreach (range(1, 120) as $i) {
            $i % 2
                ? $ledger->debit($this->party, LedgerEntryType::Sale, '100.00', null, "Sale {$i}", $day->addHours($i))
                : $ledger->credit($this->party, LedgerEntryType::CustomerPayment, '30.00', null, "Payment {$i}", $day->addHours($i));
        }
    }

    public function test_every_page_carries_the_correct_balance_forward()
    {
        $statement = app(PartyStatement::class);
        $from = CarbonImmutable::parse('2026-01-01');
        $to = CarbonImmutable::parse('2026-12-31');

        $all = $statement->build($this->party, $from, $to);
        $this->assertCount(120, $all['entries']);
        $this->assertNull($all['pagination']);

        $pages = [1, 2, 3];
        $paged = array_map(fn (int $page) => $statement->build($this->party, $from, $to, perPage: 50, page: $page), $pages);

        $this->assertSame([50, 50, 20], array_map(fn ($p) => count($p['entries']), $paged));
        $this->assertSame(3, $paged[0]['pagination']['last_page']);
        $this->assertSame(120, $paged[0]['pagination']['total']);

        // Concatenated pages equal the unpaginated statement, balances included.
        $this->assertSame(array_column($all['entries'], 'balance'), array_merge(...array_map(fn ($p) => array_column($p['entries'], 'balance'), $paged)));

        // Brought forward = the previous page's last balance; page 1 starts from the opening balance.
        $this->assertSame('1000.00', $paged[0]['page_opening']);
        $this->assertSame(end($paged[0]['entries'])['balance'], $paged[1]['page_opening']);
        $this->assertSame(end($paged[1]['entries'])['balance'], $paged[2]['page_opening']);

        // Totals and closing cover the whole period on every page: 1000 + 60×100 − 60×30.
        foreach ($paged as $page) {
            $this->assertSame('6000.00', $page['total_debit']);
            $this->assertSame('1800.00', $page['total_credit']);
            $this->assertSame('5200.00', $page['closing_balance']);
        }

        $this->assertSame('5200.00', end($paged[2]['entries'])['balance']);
        $this->assertSame('5200.00', $this->party->fresh()->balance);
    }

    public function test_party_page_paginates_the_statement()
    {
        $this->actingAs($this->admin())->get("/parties/{$this->party->id}?from=2026-01-01&to=2026-12-31&statement_page=3")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('statement.entries', 20)
                ->where('statement.pagination.current_page', 3)
                ->where('statement.pagination.total', 120)
                ->where('statement.closing_balance', '5200.00')
                ->where('statement.entries.19.balance', '5200.00'));
    }
}
