<?php

namespace App\Enums;

enum OpeningBalanceType: string
{
    /** The party owed the shop when it was added. */
    case Receivable = 'RECEIVABLE';

    /** The shop owed the party when it was added. */
    case Payable = 'PAYABLE';

    public function label(): string
    {
        return match ($this) {
            self::Receivable => 'Receivable (party owes shop)',
            self::Payable => 'Payable (shop owes party)',
        };
    }
}
