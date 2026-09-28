<?php

namespace App\Enums;

/**
 * Expenses are never deleted: a mistaken expense is VOID (kept for audit, excluded from totals).
 */
enum ExpenseStatus: string
{
    case Recorded = 'RECORDED';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Void => 'Void',
        };
    }
}
