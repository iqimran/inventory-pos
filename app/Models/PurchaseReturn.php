<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PurchaseReturn extends Model
{
    use HasUserstamps;

    protected $fillable = ['return_no', 'purchase_id', 'party_id', 'return_date', 'total', 'refund_amount', 'reason'];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'total' => 'decimal:2',
            'refund_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Purchase returns cannot be deleted.'));
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasMany<PurchaseReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}
