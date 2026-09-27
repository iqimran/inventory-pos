<?php

namespace App\Domain\PartyLedger;

use App\Enums\LedgerEntryType;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single writer of party ledger entries and the cached party balance.
 *
 * Convention: debit increases what the party owes the shop, credit increases what the
 * shop owes the party. Balance = SUM(debit - credit); positive = receivable / advance held
 * by the party, negative = payable to the party.
 *
 * Lock order for workflows touching several resources: party row first, then stock.
 */
class PartyLedgerService
{
    /**
     * Lock the party row for the rest of the current transaction and return it fresh.
     */
    public function lock(Party|int $party): Party
    {
        $partyId = $party instanceof Party ? $party->getKey() : $party;

        return Party::whereKey($partyId)->lockForUpdate()->firstOrFail();
    }

    public function debit(Party $party, LedgerEntryType $type, string $amount, ?Model $reference = null, ?string $description = null, ?DateTimeInterface $occurredAt = null): PartyLedgerEntry
    {
        return $this->post($party, $type, $amount, '0.00', $reference, $description, $occurredAt);
    }

    public function credit(Party $party, LedgerEntryType $type, string $amount, ?Model $reference = null, ?string $description = null, ?DateTimeInterface $occurredAt = null): PartyLedgerEntry
    {
        return $this->post($party, $type, '0.00', $amount, $reference, $description, $occurredAt);
    }

    /**
     * Balance recomputed from the ledger (the source of truth).
     */
    public function ledgerBalance(Party|int $party): string
    {
        $partyId = $party instanceof Party ? $party->getKey() : $party;

        $sum = PartyLedgerEntry::where('party_id', $partyId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance');

        return Money::of((string) $sum);
    }

    /**
     * Balance of all entries that occurred strictly before the given moment (statement opening).
     */
    public function balanceBefore(Party $party, DateTimeInterface $moment): string
    {
        $sum = PartyLedgerEntry::where('party_id', $party->getKey())
            ->where('occurred_at', '<', $moment)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance');

        return Money::of((string) $sum);
    }

    private function post(Party $party, LedgerEntryType $type, string $debit, string $credit, ?Model $reference, ?string $description, ?DateTimeInterface $occurredAt): PartyLedgerEntry
    {
        $debit = Money::of($debit);
        $credit = Money::of($credit);

        if (Money::isNegative($debit) || Money::isNegative($credit) || Money::isZero(Money::add($debit, $credit))
            || (Money::isPositive($debit) && Money::isPositive($credit))) {
            throw new InvalidArgumentException('A ledger entry needs exactly one positive debit or credit amount.');
        }

        return DB::transaction(function () use ($party, $type, $debit, $credit, $reference, $description, $occurredAt): PartyLedgerEntry {
            $locked = $this->lock($party);
            $balance = Money::add(Money::of($locked->balance), $debit, Money::negate($credit));

            $entry = PartyLedgerEntry::create([
                'party_id' => $locked->id,
                'entry_type' => $type,
                'debit' => $debit,
                'credit' => $credit,
                'balance_after' => $balance,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
                'created_by' => Auth::id(),
            ]);

            // Direct query: the cache is not a user edit, so leave updated_at/updated_by alone.
            Party::whereKey($locked->id)->toBase()->update(['balance' => $balance]);
            $party->setRawAttributes(array_merge($party->getAttributes(), ['balance' => $balance]), true);

            return $entry;
        });
    }
}
