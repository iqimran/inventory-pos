<?php

namespace App\Http\Resources;

use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceInvoice
 */
class ServiceInvoiceResource extends JsonResource
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
            'status' => $this->status->value,
            'invoiced_at' => $this->invoiced_at->toIso8601String(),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'product_total' => $this->product_total,
            'service_total' => $this->service_total,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->due_amount,
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'payment_method_label' => $this->payment_method?->label(),
            'notes' => $this->notes,
            'cost_total' => $this->when($showCost, $this->cost_total),
            'party' => $this->whenLoaded('party', fn () => [
                'id' => $this->party->id, 'name' => $this->party->name, 'phone' => $this->party->phone, 'address' => $this->party->address,
            ]),
            'job' => $this->whenLoaded('serviceJob', fn () => [
                'id' => $this->serviceJob->id,
                'job_no' => $this->serviceJob->job_no,
                'status' => $this->serviceJob->status->value,
                'status_label' => $this->serviceJob->status->label(),
                'complaint' => $this->serviceJob->complaint,
                'diagnosis' => $this->serviceJob->diagnosis,
                'received_at' => $this->serviceJob->received_at->toIso8601String(),
                'device' => $this->serviceJob->relationLoaded('device') ? new DeviceResource($this->serviceJob->device) : null,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => array_merge([
                'id' => $item->id,
                'line_type' => $item->line_type->value,
                'line_type_label' => $item->line_type->label(),
                'product_id' => $item->product_id,
                'sku' => $item->product?->sku,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_subtotal' => $item->line_subtotal,
                'discount_share' => $item->discount_share,
                'line_total' => $item->line_total,
            ], $showCost ? ['unit_cost' => $item->unit_cost, 'cost_total' => $item->cost_total] : []))),
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
