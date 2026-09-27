<?php

namespace App\Enums;

/**
 * Completed sales are financial history and are never deleted. Returns (T024) are recorded as
 * separate documents; cancellation, when added, must be an explicit status, not a deletion.
 */
enum SaleStatus: string
{
    case Completed = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
        };
    }
}
