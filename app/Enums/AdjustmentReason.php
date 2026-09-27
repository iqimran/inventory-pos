<?php

namespace App\Enums;

enum AdjustmentReason: string
{
    case OpeningStock = 'OPENING_STOCK';
    case CountCorrection = 'COUNT_CORRECTION';
    case Damaged = 'DAMAGED';
    case Lost = 'LOST';
    case Found = 'FOUND';
    case InternalUse = 'INTERNAL_USE';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::OpeningStock => 'Opening stock',
            self::CountCorrection => 'Stock count correction',
            self::Damaged => 'Damaged',
            self::Lost => 'Lost / stolen',
            self::Found => 'Found',
            self::InternalUse => 'Internal use',
            self::Other => 'Other',
        };
    }

    /**
     * Directions this reason may be used with.
     *
     * @return list<StockMovementType>
     */
    public function allowedTypes(): array
    {
        return match ($this) {
            self::OpeningStock, self::Found => [StockMovementType::AdjustmentIn],
            self::Damaged, self::Lost, self::InternalUse => [StockMovementType::AdjustmentOut],
            self::CountCorrection, self::Other => [StockMovementType::AdjustmentIn, StockMovementType::AdjustmentOut],
        };
    }

    /**
     * @return list<array{value: string, label: string, directions: list<string>}>
     */
    public static function options(): array
    {
        return array_map(fn (self $reason) => [
            'value' => $reason->value,
            'label' => $reason->label(),
            'directions' => array_map(fn (StockMovementType $type) => $type->isInbound() ? 'in' : 'out', $reason->allowedTypes()),
        ], self::cases());
    }
}
