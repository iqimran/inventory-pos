<?php

namespace App\Enums;

/**
 * Stock movement types. The type fixes the direction; StockService applies the sign.
 * Types for purchase, sale and service are recorded by those modules when built.
 */
enum StockMovementType: string
{
    case PurchaseIn = 'PURCHASE_IN';
    case PurchaseReturnOut = 'PURCHASE_RETURN_OUT';
    case SaleOut = 'SALE_OUT';
    case SaleReturnIn = 'SALE_RETURN_IN';
    case ServicePartOut = 'SERVICE_PART_OUT';
    case AdjustmentIn = 'ADJUSTMENT_IN';
    case AdjustmentOut = 'ADJUSTMENT_OUT';

    /** +1 for stock in, -1 for stock out. */
    public function direction(): int
    {
        return match ($this) {
            self::PurchaseIn, self::SaleReturnIn, self::AdjustmentIn => 1,
            self::PurchaseReturnOut, self::SaleOut, self::ServicePartOut, self::AdjustmentOut => -1,
        };
    }

    public function isInbound(): bool
    {
        return $this->direction() > 0;
    }

    public function label(): string
    {
        return match ($this) {
            self::PurchaseIn => 'Purchase',
            self::PurchaseReturnOut => 'Purchase return',
            self::SaleOut => 'Sale',
            self::SaleReturnIn => 'Sale return',
            self::ServicePartOut => 'Service part',
            self::AdjustmentIn => 'Adjustment (in)',
            self::AdjustmentOut => 'Adjustment (out)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
