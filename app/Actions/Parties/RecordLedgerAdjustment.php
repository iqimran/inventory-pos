<?php

namespace App\Actions\Parties;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Enums\LedgerEntryType;
use App\Models\Party;
use App\Models\PartyLedgerEntry;

class RecordLedgerAdjustment
{
    public function __construct(private readonly PartyLedgerService $ledger) {}

    /**
     * @param  'debit'|'credit'  $side  debit = party owes the shop more; credit = shop owes the party more
     */
    public function handle(Party $party, string $side, string $amount, string $reason): PartyLedgerEntry
    {
        return $side === 'debit'
            ? $this->ledger->debit($party, LedgerEntryType::ManualAdjustment, $amount, null, $reason)
            : $this->ledger->credit($party, LedgerEntryType::ManualAdjustment, $amount, null, $reason);
    }
}
