<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileLedger extends Command
{
    protected $signature = 'ledger:reconcile {--fix : Rewrite mismatched party balances from the ledger}';

    protected $description = 'Verify cached party balances, and payment allocation totals, against their source records';

    public function handle(): int
    {
        $ledger = DB::table('party_ledger_entries')
            ->select('party_id', DB::raw('SUM(debit) - SUM(credit) as ledger_balance'))
            ->groupBy('party_id');

        $parties = DB::table('parties')
            ->leftJoinSub($ledger, 'ledger', 'ledger.party_id', '=', 'parties.id')
            // Compare at cent precision (SQLite sums decimals as floating point).
            ->whereRaw('ROUND(COALESCE(ledger.ledger_balance, 0), 2) <> ROUND(parties.balance, 2)')
            ->get(['parties.id', 'parties.name', 'parties.balance', DB::raw('ROUND(COALESCE(ledger.ledger_balance, 0), 2) as ledger_balance')]);

        $allocations = DB::table('payment_allocations')
            ->select('payment_id', DB::raw('SUM(amount) as allocated'))
            ->groupBy('payment_id');

        $payments = DB::table('payments')
            ->leftJoinSub($allocations, 'alloc', 'alloc.payment_id', '=', 'payments.id')
            ->where(fn ($q) => $q->whereRaw('ROUND(COALESCE(alloc.allocated, 0), 2) <> ROUND(payments.allocated_amount, 2)')->orWhereRaw('payments.allocated_amount > payments.amount'))
            ->get(['payments.id', 'payments.payment_no', 'payments.allocated_amount', DB::raw('ROUND(COALESCE(alloc.allocated, 0), 2) as allocated')]);

        if ($parties->isEmpty() && $payments->isEmpty()) {
            $this->components->info('All party balances and payment allocations match their records.');

            return self::SUCCESS;
        }

        if ($parties->isNotEmpty()) {
            $this->table(['Party ID', 'Name', 'Cached', 'Ledger'], $parties->map(fn ($r) => [$r->id, $r->name, $r->balance, $r->ledger_balance]));
        }

        if ($payments->isNotEmpty()) {
            $this->table(['Payment ID', 'No', 'Cached allocated', 'Allocations'], $payments->map(fn ($r) => [$r->id, $r->payment_no, $r->allocated_amount, $r->allocated]));
        }

        if (! $this->option('fix')) {
            $this->components->error('Mismatches found. Re-run with --fix to repair cached values.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($parties, $payments): void {
            foreach ($parties as $row) {
                DB::table('parties')->where('id', $row->id)->update(['balance' => $row->ledger_balance]);
            }

            foreach ($payments as $row) {
                DB::table('payments')->where('id', $row->id)->update(['allocated_amount' => $row->allocated]);
            }
        });

        $this->components->info('Cached values repaired from source records.');

        return self::SUCCESS;
    }
}
