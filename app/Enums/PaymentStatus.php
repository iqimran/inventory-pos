<?php

namespace App\Enums;

use App\Support\Money;

enum PaymentStatus: string
{
    case Paid = 'PAID';
    case Partial = 'PARTIAL';
    case Due = 'DUE';

    /**
     * PAID: due = 0; PARTIAL: paid > 0 and due > 0; DUE: paid = 0 and due > 0.
     */
    public static function resolve(string $paid, string $due): self
    {
        if (! Money::isPositive($due)) {
            return self::Paid;
        }

        return Money::isPositive($paid) ? self::Partial : self::Due;
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Partial => 'Partial',
            self::Due => 'Due',
        };
    }
}
