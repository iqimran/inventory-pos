<?php

namespace App\Actions\Parties;

use App\Domain\Audit\AuditTrail;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Enums\LedgerEntryType;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use Illuminate\Support\Facades\DB;

class RecordLedgerAdjustment
{
    public function __construct(private readonly PartyLedgerService $ledger, private readonly AuditTrail $audit) {}

    /**
     * @param  'debit'|'credit'  $side  debit = party owes the shop more; credit = shop owes the party more
     */
    public function handle(Party $party, string $side, string $amount, string $reason): PartyLedgerEntry
    {
        return DB::transaction(function () use ($party, $side, $amount, $reason): PartyLedgerEntry {
            $before = $this->ledger->lock($party)->balance;
            $entry = $side === 'debit'
                ? $this->ledger->debit($party, LedgerEntryType::ManualAdjustment, $amount, null, $reason)
                : $this->ledger->credit($party, LedgerEntryType::ManualAdjustment, $amount, null, $reason);

            $this->audit->record('ledger.adjusted', $party, ['balance' => $before], [
                'balance' => $entry->balance_after, 'side' => $side, 'amount' => $amount, 'ledger_entry_id' => $entry->id,
            ], $reason);

            return $entry;
        });
    }
}
