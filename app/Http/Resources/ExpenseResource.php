<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_no' => $this->expense_no,
            'expense_type_id' => $this->expense_type_id,
            'type' => $this->whenLoaded('type', fn () => ['id' => $this->type->id, 'name' => $this->type->name]),
            'amount' => $this->amount,
            'expense_date' => $this->expense_date->toDateString(),
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'voided_by' => $this->whenLoaded('voider', fn () => $this->voider?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'audits' => $this->whenLoaded('audits', fn () => $this->audits->map(fn ($audit) => [
                'id' => $audit->id,
                'action' => $audit->action->value,
                'action_label' => $audit->action->label(),
                'changes' => $audit->changes,
                'reason' => $audit->reason,
                'created_at' => $audit->created_at?->toIso8601String(),
                'created_by' => $audit->creator?->name,
            ])),
            'can' => [
                'update' => (bool) $request->user()?->can('update', $this->resource) && ! $this->isVoid(),
                'void' => (bool) $request->user()?->can('void', $this->resource) && ! $this->isVoid(),
            ],
        ];
    }
}
