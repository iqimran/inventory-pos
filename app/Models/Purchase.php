<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A completed purchase. Financial history: never deleted; corrected with purchase returns.
 * paid_amount / returned_amount / due_amount / payment_status are caches maintained by the purchasing actions.
 */
class Purchase extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'purchase_no',
        'party_id',
        'purchase_date',
        'supplier_invoice_no',
        'subtotal',
        'discount',
        'total',
        'paid_amount',
        'returned_amount',
        'due_amount',
        'payment_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Purchases cannot be deleted; record a purchase return instead.'));
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasMany<PurchaseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * @return HasMany<PurchaseReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * @return MorphMany<PaymentAllocation, $this>
     */
    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }
}
