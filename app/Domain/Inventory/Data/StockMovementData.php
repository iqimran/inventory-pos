<?php

namespace App\Domain\Inventory\Data;

use App\Enums\AdjustmentReason;
use App\Enums\StockMovementType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One requested stock change. Quantity is always positive; the type decides the direction.
 */
final readonly class StockMovementData
{
    public function __construct(
        public int $productId,
        public StockMovementType $type,
        public int $quantity,
        public ?string $unitCost = null,
        public ?Model $reference = null,
        public ?AdjustmentReason $reason = null,
        public ?string $notes = null,
        public ?DateTimeInterface $occurredAt = null,
    ) {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Stock movement quantity must be a positive integer.');
        }
    }

    public function signedQuantity(): int
    {
        return $this->quantity * $this->type->direction();
    }
}
