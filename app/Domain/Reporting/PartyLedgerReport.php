<?php

namespace App\Domain\Reporting;

use App\Enums\LedgerEntryType;
use App\Enums\PartyType;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Party balances and period summaries from the party ledger (debit = party owes the shop more,
 * credit = the shop owes the party more; balance > 0 receivable, < 0 payable).
 */
class PartyLedgerReport
{
    /** Ledger entry types by report column. */
    public const TRANSACTIONS = [LedgerEntryType::Sale, LedgerEntryType::ServiceInvoice, LedgerEntryType::Purchase];

    public const PAYMENTS = [
        LedgerEntryType::CustomerPayment, LedgerEntryType::PurchasePayment, LedgerEntryType::SupplierAdvance,
        LedgerEntryType::SupplierRefund, LedgerEntryType::CustomerRefund,
    ];

    public const RETURNS = [LedgerEntryType::SaleReturn, LedgerEntryType::PurchaseReturn];

    /** Everything else (opening balances, manual adjustments) is shown as "adjustments". */

    /**
     * Current outstanding balances (the cached, reconcilable party balance).
     *
     * @return array{receivable: string, receivable_parties: int, payable: string, payable_parties: int}
     */
    public function outstanding(?string $type = null): array
    {
        $row = $this->parties($type)
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as receivable,
                COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) as receivable_parties,
                COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END), 0) as payable,
                COALESCE(SUM(CASE WHEN balance < 0 THEN 1 ELSE 0 END), 0) as payable_parties')
            ->first();

        return [
            'receivable' => Money::of((string) $row->receivable),
            'receivable_parties' => (int) $row->receivable_parties,
            'payable' => Money::of((string) $row->payable),
            'payable_parties' => (int) $row->payable_parties,
        ];
    }

    /**
     * Opening balance, transactions, payments, returns, adjustments and closing balance per party
     * for the period (signed: + increases what the party owes the shop).
     *
     * @param  array{type?: ?string, side?: ?string, q?: ?string}  $filters  side: receivable | payable
     */
    public function summary(ReportPeriod $period, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        [$start, $end] = $period->datetimeBounds();
        $signed = '(party_ledger_entries.debit - party_ledger_entries.credit)';
        $inPeriod = 'party_ledger_entries.occurred_at >= ?';

        $ledger = DB::table('party_ledger_entries')
            ->where('occurred_at', '<=', $end)
            ->groupBy('party_id')
            ->select('party_id')
            ->selectRaw("SUM(CASE WHEN party_ledger_entries.occurred_at < ? THEN {$signed} ELSE 0 END) as opening", [$start])
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND entry_type IN ({$this->list(self::TRANSACTIONS)}) THEN {$signed} ELSE 0 END) as transactions", [$start])
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND entry_type IN ({$this->list(self::PAYMENTS)}) THEN {$signed} ELSE 0 END) as payments", [$start])
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND entry_type IN ({$this->list(self::RETURNS)}) THEN {$signed} ELSE 0 END) as returns", [$start])
            ->selectRaw("SUM(CASE WHEN {$inPeriod} AND entry_type NOT IN ({$this->list([...self::TRANSACTIONS, ...self::PAYMENTS, ...self::RETURNS])}) THEN {$signed} ELSE 0 END) as adjustments", [$start])
            ->selectRaw("SUM(CASE WHEN {$inPeriod} THEN 1 ELSE 0 END) as entries", [$start])
            ->selectRaw("SUM({$signed}) as closing");

        $closing = 'ledger.closing';

        return $this->parties($filters['type'] ?? null)
            ->joinSub($ledger, 'ledger', 'ledger.party_id', '=', 'parties.id')
            ->when(($filters['side'] ?? null) === 'receivable', fn (Builder $q) => $q->where($closing, '>', 0))
            ->when(($filters['side'] ?? null) === 'payable', fn (Builder $q) => $q->where($closing, '<', 0))
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function (Builder $q) use ($filters) {
                $term = trim((string) $filters['q']);
                $contains = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                $digits = preg_replace('/\D+/', '', $term);
                $q->where(fn (Builder $w) => $w->whereRaw("parties.name LIKE ? ESCAPE '!'", [$contains])
                    ->when(strlen($digits) >= 3, fn (Builder $p) => $p->orWhere('parties.phone', 'like', "%{$digits}%")));
            })
            // Only parties with a balance or activity in the period.
            ->where(fn (Builder $q) => $q->where('ledger.entries', '>', 0)->orWhere('ledger.opening', '<>', 0))
            ->select(['parties.id', 'parties.name', 'parties.type', 'parties.phone', 'ledger.opening', 'ledger.transactions', 'ledger.payments', 'ledger.returns', 'ledger.adjustments', 'ledger.entries', 'ledger.closing'])
            ->orderBy('parties.name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'type' => PartyType::from($r->type)->label(),
                'phone' => $r->phone,
                'opening' => Money::of((string) $r->opening),
                'transactions' => Money::of((string) $r->transactions),
                'payments' => Money::of((string) $r->payments),
                'returns' => Money::of((string) $r->returns),
                'adjustments' => Money::of((string) $r->adjustments),
                'entries' => (int) $r->entries,
                'closing' => Money::of((string) $r->closing),
            ]);
    }

    private function parties(?string $type): Builder
    {
        return DB::table('parties')->when($type, fn (Builder $q, string $t) => $q->where('parties.type', $t));
    }

    /**
     * SQL list of entry type values (enum values from code, never from input).
     *
     * @param  list<LedgerEntryType>  $types
     */
    private function list(array $types): string
    {
        return implode(', ', array_map(fn (LedgerEntryType $type) => "'{$type->value}'", $types));
    }
}
