<?php

namespace App\Http\Requests\Service;

use App\Support\Validation\MoneyRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ServiceJobPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('serviceJob'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The product is fixed once a part is on the job; to change it, remove the line.
            'product_id' => $this->route('item') ? ['prohibited'] : ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.MoneyRules::MAX_UNIT],
        ];
    }

    /**
     * @return array{product_id?: int, quantity: int, unit_price: ?string}
     */
    public function partData(): array
    {
        $data = $this->validated();

        return array_filter([
            'product_id' => isset($data['product_id']) ? (int) $data['product_id'] : null,
            'quantity' => (int) $data['quantity'],
        ], fn ($value) => $value !== null) + ['unit_price' => isset($data['unit_price']) ? (string) $data['unit_price'] : null];
    }
}
