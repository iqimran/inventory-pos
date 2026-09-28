<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_no' => $this->payment_no,
            'direction' => $this->direction->value,
            'purpose' => $this->purpose->value,
            'purpose_label' => $this->purpose->label(),
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'amount' => $this->amount,
            'allocated_amount' => $this->allocated_amount,
            'unallocated_amount' => $this->unallocatedAmount(),
            'reference_no' => $this->reference_no,
            'paid_at' => $this->paid_at->toIso8601String(),
            'notes' => $this->notes,
            'party' => $this->whenLoaded('party', fn () => $this->party ? ['id' => $this->party->id, 'name' => $this->party->name, 'phone' => $this->party->phone] : null),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'id' => $allocation->id,
                'amount' => $allocation->amount,
                'document' => match (true) {
                    $allocation->allocatable instanceof Purchase => ['type' => 'purchase', 'id' => $allocation->allocatable->id, 'number' => $allocation->allocatable->purchase_no],
                    $allocation->allocatable instanceof Sale => ['type' => 'sale', 'id' => $allocation->allocatable->id, 'number' => $allocation->allocatable->invoice_no],
                    $allocation->allocatable instanceof ServiceInvoice => ['type' => 'service_invoice', 'id' => $allocation->allocatable->id, 'number' => $allocation->allocatable->invoice_no],
                    default => null,
                },
                'created_at' => $allocation->created_at?->toIso8601String(),
            ])),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
