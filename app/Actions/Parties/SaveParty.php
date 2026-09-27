<?php

namespace App\Actions\Parties;

use App\Domain\PartyLedger\PartyLedgerService;
use App\Enums\LedgerEntryType;
use App\Enums\OpeningBalanceType;
use App\Models\Party;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SaveParty
{
    public function __construct(private readonly PartyLedgerService $ledger) {}

    /**
     * Create a party (posting its opening balance to the ledger) or update its details.
     * The opening balance is fixed once created; later corrections are ledger adjustments.
     *
     * @param  array<string, mixed>  $data  validated attributes
     */
    public function handle(?Party $party, array $data): Party
    {
        if ($party) {
            $party->update(collect($data)->except(['opening_balance', 'opening_balance_type'])->all());

            return $party;
        }

        return DB::transaction(function () use ($data): Party {
            $opening = Money::of($data['opening_balance'] ?? '0');
            $type = Money::isPositive($opening) ? OpeningBalanceType::from($data['opening_balance_type']) : null;

            $party = Party::create([
                ...collect($data)->except(['opening_balance', 'opening_balance_type'])->all(),
                'opening_balance' => $opening,
                'opening_balance_type' => $type,
            ]);

            if ($type === OpeningBalanceType::Receivable) {
                $this->ledger->debit($party, LedgerEntryType::OpeningBalance, $opening, $party, 'Opening balance');
            } elseif ($type === OpeningBalanceType::Payable) {
                $this->ledger->credit($party, LedgerEntryType::OpeningBalance, $opening, $party, 'Opening balance');
            }

            return $party;
        });
    }
}
