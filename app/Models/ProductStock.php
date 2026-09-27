<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maintained running balance. Read-only outside App\Domain\Inventory\StockService.
 */
class ProductStock extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'average_cost' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
