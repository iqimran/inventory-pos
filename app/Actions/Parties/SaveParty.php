<?php

namespace App\Actions\Parties;

use App\Domain\Audit\AuditTrail;
use App\Domain\PartyLedger\PartyLedgerService;
use App\Enums\LedgerEntryType;
use App\Enums\OpeningBalanceType;
use App\Models\Party;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SaveParty
{
    private const AUDITED = ['name', 'type', 'phone', 'email', 'address', 'is_active'];

    public function __construct(private readonly PartyLedgerService $ledger, private readonly AuditTrail $audit) {}

    /**
     * Create a party (posting its opening balance to the ledger) or update its details.
     * The opening balance is fixed once created; later corrections are ledger adjustments.
     *
     * @param  array<string, mixed>  $data  validated attributes
     */
    public function handle(?Party $party, array $data): Party
    {
        if ($party) {
            return DB::transaction(function () use ($party, $data): Party {
                $before = $this->audit->snapshot($party, self::AUDITED);
                $party->update(collect($data)->except(['opening_balance', 'opening_balance_type'])->all());
                $this->audit->recordChanges('party.updated', $party, $before, $this->audit->snapshot($party, self::AUDITED), $party->name);

                return $party;
            });
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

            $this->audit->record('party.created', $party, new: [
                ...$this->audit->snapshot($party, self::AUDITED),
                'opening_balance' => $opening,
                'opening_balance_type' => $type?->value,
            ], description: $party->name);

            return $party;
        });
    }
}
