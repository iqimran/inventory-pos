<?php

namespace App\Http\Resources;

use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleReturn
 */
class SaleReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $showCost = (bool) $request->user()?->can('purchases.view');

        return [
            'id' => $this->id,
            'return_no' => $this->return_no,
            'status' => $this->status->value,
            'returned_at' => $this->returned_at->toIso8601String(),
            'subtotal' => $this->subtotal,
            'adjustment_amount' => $this->adjustment_amount,
            'refund_amount' => $this->refund_amount,
            'credit_amount' => $this->credit_amount,
            'refund_method_label' => $this->refund_method?->label(),
            'reason' => $this->reason,
            'sale' => $this->whenLoaded('sale', fn () => ['id' => $this->sale->id, 'invoice_no' => $this->sale->invoice_no]),
            'party' => $this->whenLoaded('party', fn () => $this->party ? ['id' => $this->party->id, 'name' => $this->party->name, 'phone' => $this->party->phone] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => array_merge([
                'id' => $item->id,
                'product' => $item->product ? ['name' => $item->product->name, 'sku' => $item->product->sku] : null,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'amount' => $item->amount,
            ], $showCost ? ['unit_cost' => $item->unit_cost] : []))),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
