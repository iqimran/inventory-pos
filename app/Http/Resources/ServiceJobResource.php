<?php

namespace App\Http\Resources;

use App\Models\ServiceJob;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceJob
 */
class ServiceJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_no' => $this->job_no,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'next_statuses' => array_map(fn ($status) => ['value' => $status->value, 'label' => $status->label()], $this->status->transitions()),
            'complaint' => $this->complaint,
            'diagnosis' => $this->diagnosis,
            'estimated_amount' => $this->estimated_amount,
            'approved_amount' => $this->approved_amount,
            'service_charge' => $this->service_charge,
            'received_at' => $this->received_at->toIso8601String(),
            'promised_at' => $this->promised_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'notes' => $this->notes,
            'technician_id' => $this->technician_id,
            'technician' => $this->whenLoaded('technician', fn () => $this->technician?->name),
            'party' => $this->whenLoaded('party', fn () => [
                'id' => $this->party->id, 'name' => $this->party->name, 'phone' => $this->party->phone, 'balance' => $this->party->balance,
            ]),
            'device' => $this->whenLoaded('device', fn () => new DeviceResource($this->device)),
            'parts' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product ? ['name' => $item->product->name, 'sku' => $item->product->sku] : null,
                'stock' => $item->product?->stockQuantity(),
                'quantity' => $item->quantity,
                'list_price' => $item->list_price,
                'unit_price' => $item->unit_price,
                'price_overridden' => $item->price_overridden,
                'line_total' => $item->line_total,
                'consumed_at' => $item->consumed_at?->toIso8601String(),
            ])),
            'charges' => $this->whenLoaded('charges', fn () => $this->charges->map(fn ($charge) => [
                'id' => $charge->id,
                'description' => $charge->description,
                'amount' => $charge->amount,
            ])),
            // Draft bill: parts + charges before any invoice discount.
            'parts_total' => $this->whenLoaded('items', fn () => Money::add('0.00', ...$this->items->map(fn ($item) => Money::of($item->line_total))->all())),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'invoice_no' => $this->invoice->invoice_no,
                'total' => $this->invoice->total,
                'due_amount' => $this->invoice->due_amount,
                'payment_status' => $this->invoice->payment_status->value,
                'payment_status_label' => $this->invoice->payment_status->label(),
            ] : null),
            'status_logs' => $this->whenLoaded('statusLogs', fn () => $this->statusLogs->map(fn ($log) => [
                'id' => $log->id,
                'from_status_label' => $log->from_status?->label(),
                'to_status' => $log->to_status->value,
                'to_status_label' => $log->to_status->label(),
                'notes' => $log->notes,
                'created_at' => $log->created_at?->toIso8601String(),
                'created_by' => $log->creator?->name,
            ])),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
