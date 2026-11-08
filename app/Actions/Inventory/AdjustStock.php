<?php

namespace App\Actions\Inventory;

use App\Domain\Audit\AuditTrail;
use App\Domain\Inventory\Data\StockMovementData;
use App\Domain\Inventory\StockService;
use App\Enums\AdjustmentReason;
use App\Enums\StockMovementType;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustStock
{
    public function __construct(private readonly StockService $stock, private readonly AuditTrail $audit) {}

    /**
     * Record a manual stock adjustment as a movement with its reason.
     *
     * @throws ValidationException
     */
    public function handle(int $productId, string $direction, int $quantity, AdjustmentReason $reason, ?string $notes = null): StockMovement
    {
        $type = $direction === 'in' ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut;

        if (! in_array($type, $reason->allowedTypes(), true)) {
            throw ValidationException::withMessages([
                'reason' => "“{$reason->label()}” cannot be used to ".($type->isInbound() ? 'add' : 'remove').' stock.',
            ]);
        }

        return DB::transaction(function () use ($productId, $type, $quantity, $reason, $notes): StockMovement {
            $movement = $this->stock->record(new StockMovementData(
                productId: $productId,
                type: $type,
                quantity: $quantity,
                reason: $reason,
                notes: $notes,
            ));

            $this->audit->record('stock.adjusted', $movement->product, ['stock' => $movement->balance_after - $movement->quantity], [
                'stock' => $movement->balance_after, 'quantity' => $movement->quantity, 'reason' => $reason->value, 'stock_movement_id' => $movement->id,
            ], $notes ?? $reason->label());

            return $movement;
        });
    }
}
