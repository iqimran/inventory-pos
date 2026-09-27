<?php

namespace App\Http\Resources;

use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Party
 */
class PartyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'opening_balance' => $this->opening_balance,
            'opening_balance_type' => $this->opening_balance_type?->value,
            'balance' => $this->balance,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
