<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'list_price',
        'unit_price',
        'price_overridden',
        'line_subtotal',
        'line_discount',
        'discount_share',
        'line_total',
        'unit_cost',
        'cost_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'list_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'price_overridden' => 'boolean',
            'line_subtotal' => 'decimal:2',
            'line_discount' => 'decimal:2',
            'discount_share' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
