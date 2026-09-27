<?php

namespace Tests\Feature\Purchasing;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Domain\PartyLedger\PartyStatement;
use App\Enums\LedgerEntryType;
use App\Enums\Permission;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class PartyLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function createParty(array $data): TestResponse
    {
        return $this->actingAs($this->admin())->post('/parties', array_merge([
            'name' => 'Rahim Traders', 'type' => 'SUPPLIER', 'phone' => '+880 1711-000000', 'is_active' => true,
        ], $data));
    }

    public function test_party_can_be_supplier_customer_or_both()
    {
        foreach (['SUPPLIER', 'CUSTOMER', 'BOTH'] as $type) {
            $this->createParty(['name' => "Party {$type}", 'type' => $type])->assertSessionHasNoErrors();
        }

        $this->assertSame(['BOTH', 'CUSTOMER', 'SUPPLIER'], Party::orderBy('type')->pluck('type')->map->value->all());
        $this->assertSame(2, Party::suppliers()->count());
        $this->createParty(['type' => 'VENDOR'])->assertSessionHasErrors('type');
    }

    public function test_opening_payable_is_posted_as_a_credit()
    {
        $this->createParty(['opening_balance' => '2500.00', 'opening_balance_type' => 'PAYABLE'])->assertSessionHasNoErrors();

        $party = Party::sole();
        $entry = $party->ledgerEntries()->sole();
        $this->assertSame(LedgerEntryType::OpeningBalance, $entry->entry_type);
        $this->assertSame('2500.00', $entry->credit);
        $this->assertSame('-2500.00', $party->balance);
    }

    public function test_opening_receivable_is_posted_as_a_debit()
    {
        $this->createParty(['type' => 'CUSTOMER', 'opening_balance' => '800', 'opening_balance_type' => 'RECEIVABLE']);

        $this->assertSame('800.00', Party::sole()->balance);
    }

    public function test_opening_balance_requires_a_direction_and_zero_posts_nothing()
    {
        $this->createParty(['opening_balance' => '100'])->assertSessionHasErrors('opening_balance_type');
        $this->createParty(['opening_balance' => '0'])->assertSessionHasNoErrors();

        $this->assertSame(0, PartyLedgerEntry::count());
    }

    public function test_opening_balance_cannot_be_changed_by_editing_the_party()
    {
        $this->createParty(['opening_balance' => '100', 'opening_balance_type' => 'PAYABLE']);
        $party = Party::sole();

        $this->actingAs($this->admin())->put("/parties/{$party->id}", [
            'name' => 'Renamed', 'type' => 'BOTH', 'is_active' => true, 'opening_balance' => '9999', 'opening_balance_type' => 'RECEIVABLE',
        ])->assertSessionHasNoErrors();

        $party->refresh();
        $this->assertSame('Renamed', $party->name);
        $this->assertSame('100.00', $party->opening_balance);
        $this->assertSame('-100.00', $party->balance);
        $this->assertSame(1, PartyLedgerEntry::count());
    }

    public function test_manual_adjustment_requires_permission_and_reason()
    {
        $party = Party::factory()->create();
        $user = $this->generalUser();

        $this->actingAs($user)->post("/parties/{$party->id}/ledger-adjustments", ['side' => 'credit', 'amount' => '50', 'reason' => 'Correction'])
            ->assertForbidden();

        $user->givePermissionTo(Permission::LedgerAdjust->value);
        $this->actingAs($user)->post("/parties/{$party->id}/ledger-adjustments", ['side' => 'credit', 'amount' => '50', 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($user)->post("/parties/{$party->id}/ledger-adjustments", ['side' => 'credit', 'amount' => '50', 'reason' => 'Price correction'])
            ->assertSessionHasNoErrors();

        $entry = PartyLedgerEntry::sole();
        $this->assertSame(LedgerEntryType::ManualAdjustment, $entry->entry_type);
        $this->assertSame('Price correction', $entry->description);
        $this->assertSame($user->id, $entry->created_by);
        $this->assertSame('-50.00', $party->fresh()->balance);
    }

    public function test_statement_is_chronological_with_running_balance()
    {
        $party = Party::factory()->create();
        $ledger = app(PartyLedgerService::class);
        $day = CarbonImmutable::parse('2026-09-10 10:00');

        $ledger->credit($party, LedgerEntryType::Purchase, '1000.00', null, 'Old purchase', $day->subMonth());
        $ledger->debit($party, LedgerEntryType::PurchasePayment, '400.00', null, 'Payment', $day);
        // Recorded later but back-dated: must appear before the payment.
        $ledger->credit($party, LedgerEntryType::Purchase, '300.00', null, 'Back-dated purchase', $day->subDay());

        $statement = app(PartyStatement::class)->build($party, $day->subDays(5), $day);

        $this->assertSame('-1000.00', $statement['opening_balance']);
        $this->assertSame(['Back-dated purchase', 'Payment'], array_column($statement['entries'], 'description'));
        $this->assertSame(['-1300.00', '-900.00'], array_column($statement['entries'], 'balance'));
        $this->assertSame('-900.00', $statement['closing_balance']);
        $this->assertSame('400.00', $statement['total_debit']);
        $this->assertSame('300.00', $statement['total_credit']);
        $this->assertSame($statement['closing_balance'], $party->fresh()->balance);
    }

    public function test_ledger_entries_are_immutable_and_validated()
    {
        $party = Party::factory()->create();
        $entry = app(PartyLedgerService::class)->debit($party, LedgerEntryType::ManualAdjustment, '10.00');

        try {
            $entry->update(['debit' => '1.00']);
            $this->fail('Ledger update should be blocked.');
        } catch (LogicException) {
        }

        try {
            app(PartyLedgerService::class)->debit($party, LedgerEntryType::ManualAdjustment, '0');
            $this->fail('Zero entry should be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_party_with_history_cannot_be_deleted_but_unused_party_can()
    {
        $used = Party::factory()->create();
        app(PartyLedgerService::class)->debit($used, LedgerEntryType::ManualAdjustment, '10.00');
        $unused = Party::factory()->create();

        $this->actingAs($this->admin())->delete("/parties/{$used->id}")->assertSessionHasErrors('record');
        $this->actingAs($this->admin())->delete("/parties/{$unused->id}")->assertRedirect('/parties');

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
    }

    public function test_general_user_can_view_but_not_manage_parties()
    {
        $user = $this->generalUser();
        $party = Party::factory()->create();

        $this->actingAs($user)->post('/parties', ['name' => 'X', 'type' => 'SUPPLIER', 'is_active' => true])->assertForbidden();
        $this->actingAs($user)->put("/parties/{$party->id}", ['name' => 'X', 'type' => 'SUPPLIER', 'is_active' => true])->assertForbidden();
        $this->actingAs($user)->delete("/parties/{$party->id}")->assertForbidden();
    }

    public function test_reconcile_detects_tampered_balance()
    {
        $party = Party::factory()->create();
        app(PartyLedgerService::class)->credit($party, LedgerEntryType::Purchase, '100.00');
        DB::table('parties')->where('id', $party->id)->update(['balance' => '5.00']);

        $this->artisan('ledger:reconcile')->assertFailed();
        $this->artisan('ledger:reconcile', ['--fix' => true])->assertSuccessful();
        $this->assertSame('-100.00', $party->fresh()->balance);
    }
}
