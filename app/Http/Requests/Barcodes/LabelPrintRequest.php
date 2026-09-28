<?php

namespace App\Http\Requests\Barcodes;

use App\Enums\LabelLayout;
use App\Enums\Permission;
use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LabelPrintRequest extends FormRequest
{
    public const MAX_LABELS = 2000;

    public function authorize(): bool
    {
        return $this->user()->can(Permission::BarcodesPrint->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_LABELS],
            'layout' => ['required', Rule::enum(LabelLayout::class)],
            'price' => ['required', 'in:retail,wholesale,none'],
            'show_sku' => ['boolean'],
            'show_shop' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $items = collect($this->input('items'));

                if ($items->sum('quantity') > self::MAX_LABELS) {
                    $validator->errors()->add('items', 'Print at most '.self::MAX_LABELS.' labels at a time.');
                }

                $missing = Product::whereKey($items->pluck('product_id'))->whereNull('barcode')->pluck('name');

                if ($missing->isNotEmpty()) {
                    $validator->errors()->add('items', 'Generate a barcode first for: '.$missing->implode(', ').'.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return ['items.required' => 'Add at least one product.', 'items.*.product_id.distinct' => 'Each product may appear only once.'];
    }
}
