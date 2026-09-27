<?php

namespace App\Http\Resources;

use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Purchase
 */
class PurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_no' => $this->purchase_no,
            'purchase_date' => $this->purchase_date->toDateString(),
            'supplier_invoice_no' => $this->supplier_invoice_no,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'returned_amount' => $this->returned_amount,
            'due_amount' => $this->due_amount,
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'notes' => $this->notes,
            'party' => $this->whenLoaded('party', fn () => ['id' => $this->party->id, 'name' => $this->party->name, 'phone' => $this->party->phone]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product ? ['name' => $item->product->name, 'sku' => $item->product->sku] : null,
                'quantity' => $item->quantity,
                'unit_cost' => $item->unit_cost,
                'line_subtotal' => $item->line_subtotal,
                'discount_share' => $item->discount_share,
                'line_total' => $item->line_total,
                'returned_quantity' => $item->returned_quantity,
                'returnable_quantity' => $item->returnableQuantity(),
            ])),
            'returns' => $this->whenLoaded('returns', fn () => $this->returns->map(fn ($return) => [
                'id' => $return->id,
                'return_no' => $return->return_no,
                'return_date' => $return->return_date->toDateString(),
                'total' => $return->total,
                'refund_amount' => $return->refund_amount,
                'reason' => $return->reason,
            ])),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'amount' => $allocation->amount,
                'payment' => [
                    'id' => $allocation->payment->id,
                    'payment_no' => $allocation->payment->payment_no,
                    'purpose_label' => $allocation->payment->purpose->label(),
                    'method_label' => $allocation->payment->method->label(),
                    'paid_at' => $allocation->payment->paid_at->toIso8601String(),
                ],
            ])),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
