<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Goods returned by a customer against an original sale. Financial history: never deleted.
 */
class SaleReturn extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'return_no',
        'sale_id',
        'party_id',
        'status',
        'returned_at',
        'subtotal',
        'adjustment_amount',
        'refund_amount',
        'credit_amount',
        'refund_method',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'refund_method' => PaymentMethod::class,
            'returned_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Sale returns cannot be deleted.'));
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasMany<SaleReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
