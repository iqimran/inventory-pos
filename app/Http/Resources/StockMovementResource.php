<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'quantity' => $this->quantity,
            'balance_after' => $this->balance_after,
            'unit_cost' => $this->unit_cost,
            'reason' => $this->reason?->value,
            'reason_label' => $this->reason?->label(),
            'notes' => $this->notes,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
            ]),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
