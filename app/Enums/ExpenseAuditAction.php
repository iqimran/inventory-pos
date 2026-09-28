<?php

namespace App\Enums;

enum ExpenseAuditAction: string
{
    case Created = 'CREATED';
    case Updated = 'UPDATED';
    case Voided = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Edited',
            self::Voided => 'Voided',
        };
    }
}
