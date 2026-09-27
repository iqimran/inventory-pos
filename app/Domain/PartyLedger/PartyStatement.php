<?php

namespace App\Domain\PartyLedger;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Chronological party statement for a date range with opening, running and closing balances.
 * Ordered by occurred_at then id, so back-dated documents appear in business-date order.
 */
class PartyStatement
{
    public function __construct(private readonly PartyLedgerService $ledger) {}

    /**
     * @return array{
     *     from: string, to: string, opening_balance: string, closing_balance: string,
     *     total_debit: string, total_credit: string,
     *     entries: list<array{id: int, occurred_at: string, entry_type: string, entry_type_label: string, description: ?string,
     *                         debit: string, credit: string, balance: string, reference_type: ?string, reference_id: ?int, created_by: ?string}>
     * }
     */
    public function build(Party $party, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $running = $this->ledger->balanceBefore($party, $start);
        $opening = $running;
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        $entries = [];

        $rows = PartyLedgerEntry::query()
            ->with('creator:id,name')
            ->where('party_id', $party->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $running = Money::add($running, $row->debit, Money::negate($row->credit));
            $totalDebit = Money::add($totalDebit, $row->debit);
            $totalCredit = Money::add($totalCredit, $row->credit);

            $entries[] = [
                'id' => $row->id,
                'occurred_at' => $row->occurred_at->toIso8601String(),
                'entry_type' => $row->entry_type->value,
                'entry_type_label' => $row->entry_type->label(),
                'description' => $row->description,
                'debit' => $row->debit,
                'credit' => $row->credit,
                'balance' => $running,
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id,
                'created_by' => $row->creator?->name,
            ];
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => $running,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'entries' => $entries,
        ];
    }
}
