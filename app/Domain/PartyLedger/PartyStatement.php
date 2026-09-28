<?php

namespace App\Domain\PartyLedger;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chronological party statement for a date range with opening, running and closing balances.
 * Ordered by occurred_at then id, so back-dated documents appear in business-date order.
 *
 * With $perPage, entries are paginated: each page starts from its balance brought forward
 * (opening + every earlier entry in the range, summed in SQL), and the range totals and closing
 * balance are aggregates — so a long statement never loads more than one page of rows.
 */
class PartyStatement
{
    public function __construct(private readonly PartyLedgerService $ledger) {}

    /**
     * @return array{
     *     from: string, to: string, opening_balance: string, closing_balance: string,
     *     total_debit: string, total_credit: string, page_opening: string,
     *     entries: list<array{id: int, occurred_at: string, entry_type: string, entry_type_label: string, description: ?string,
     *                         debit: string, credit: string, balance: string, reference_type: ?string, reference_id: ?int, created_by: ?string}>,
     *     pagination: ?array{current_page: int, last_page: int, per_page: int, total: int, from: ?int, to: ?int, links: array<int, mixed>}
     * }
     */
    public function build(Party $party, CarbonImmutable $from, CarbonImmutable $to, ?int $perPage = null, int $page = 1, string $pageName = 'statement_page'): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $opening = $this->ledger->balanceBefore($party, $start);

        $totals = $this->inRange($party, $start, $end)
            ->toBase()
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();
        $totalDebit = Money::of((string) $totals->debit);
        $totalCredit = Money::of((string) $totals->credit);

        $pagination = null;
        $pageOpening = $opening;

        if ($perPage === null) {
            $rows = $this->ordered($this->inRange($party, $start, $end))->with('creator:id,name')->get();
        } else {
            $paginator = $this->ordered($this->inRange($party, $start, $end))
                ->with('creator:id,name')
                ->paginate($perPage, ['*'], $pageName, $page)
                ->withQueryString();
            $rows = $paginator->getCollection();
            $pageOpening = Money::add($opening, $this->sumOfFirst($party, $start, $end, ($paginator->currentPage() - 1) * $perPage));
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'links' => $paginator->linkCollection()->toArray(),
            ];
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => Money::add($opening, $totalDebit, Money::negate($totalCredit)),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'page_opening' => $pageOpening,
            'entries' => $this->withRunningBalance($rows, $pageOpening),
            'pagination' => $pagination,
        ];
    }

    /**
     * @return Builder<PartyLedgerEntry>
     */
    private function inRange(Party $party, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return PartyLedgerEntry::query()
            ->where('party_id', $party->id)
            ->whereBetween('occurred_at', [$start, $end]);
    }

    /**
     * @param  Builder<PartyLedgerEntry>  $query
     * @return Builder<PartyLedgerEntry>
     */
    private function ordered(Builder $query): Builder
    {
        return $query->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * Net (debit − credit) of the first $count entries of the range, summed in the database.
     */
    private function sumOfFirst(Party $party, CarbonImmutable $start, CarbonImmutable $end, int $count): string
    {
        if ($count <= 0) {
            return '0.00';
        }

        $firstEntries = $this->ordered($this->inRange($party, $start, $end))
            ->toBase()
            ->selectRaw('debit - credit as amount')
            ->limit($count);

        return Money::of((string) DB::query()->fromSub($firstEntries, 'earlier')->sum('amount'));
    }

    /**
     * @param  Collection<int, PartyLedgerEntry>  $rows
     * @return list<array<string, mixed>>
     */
    private function withRunningBalance(Collection $rows, string $running): array
    {
        $entries = [];

        foreach ($rows as $row) {
            $running = Money::add($running, $row->debit, Money::negate($row->credit));

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

        return $entries;
    }
}
