<?php

namespace App\Http\Resources;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Cost data is only exposed to users who may see purchase costs.
        $showCost = (bool) $request->user()?->can('purchases.view');

        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'sale_type' => $this->sale_type->value,
            'sale_type_label' => $this->sale_type->label(),
            'status' => $this->status->value,
            'sold_at' => $this->sold_at->toIso8601String(),
            'subtotal' => $this->subtotal,
            'items_discount' => $this->items_discount,
            'discount' => $this->discount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->due_amount,
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'payment_method' => $this->payment_method?->value,
            'payment_method_label' => $this->payment_method?->label(),
            'tendered_amount' => $this->tendered_amount,
            'change_amount' => $this->change_amount,
            'notes' => $this->notes,
            'party' => $this->whenLoaded('party', fn () => $this->party
                ? ['id' => $this->party->id, 'name' => $this->party->name, 'phone' => $this->party->phone, 'address' => $this->party->address]
                : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => array_merge([
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product ? ['name' => $item->product->name, 'sku' => $item->product->sku] : null,
                'quantity' => $item->quantity,
                'list_price' => $item->list_price,
                'unit_price' => $item->unit_price,
                'price_overridden' => $item->price_overridden,
                'line_subtotal' => $item->line_subtotal,
                'line_discount' => $item->line_discount,
                'discount_share' => $item->discount_share,
                'line_total' => $item->line_total,
            ], $showCost ? ['unit_cost' => $item->unit_cost] : []))),
            'cost_total' => $this->when($showCost, $this->cost_total),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'amount' => $allocation->amount,
                'payment' => [
                    'id' => $allocation->payment->id,
                    'payment_no' => $allocation->payment->payment_no,
                    'method_label' => $allocation->payment->method->label(),
                    'paid_at' => $allocation->payment->paid_at->toIso8601String(),
                ],
            ])),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
