<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service / labour line on a POS sale. No stock is involved; reported as SERVICE revenue.
 */
class SaleServiceCharge extends Model
{
    protected $fillable = [
        'sale_id',
        'description',
        'amount',
        'discount_share',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount_share' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
