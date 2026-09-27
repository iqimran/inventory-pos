<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'CASH';
    case Bank = 'BANK';
    case MobileBanking = 'MOBILE_BANKING';
    case Card = 'CARD';
    case Cheque = 'CHEQUE';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank transfer',
            self::MobileBanking => 'Mobile banking',
            self::Card => 'Card',
            self::Cheque => 'Cheque',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], self::cases());
    }
}
