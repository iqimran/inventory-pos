<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'unit_cost',
        'line_subtotal',
        'discount_share',
        'line_total',
        'returned_quantity',
        'returned_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'discount_share' => 'decimal:2',
            'line_total' => 'decimal:2',
            'returned_quantity' => 'integer',
            'returned_amount' => 'decimal:2',
        ];
    }

    public function returnableQuantity(): int
    {
        return $this->quantity - $this->returned_quantity;
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
