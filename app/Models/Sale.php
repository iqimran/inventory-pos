<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A completed POS sale. Financial history: never deleted.
 * paid_amount / due_amount / payment_status are caches maintained by the sales actions.
 */
class Sale extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'invoice_no',
        'party_id',
        'sale_type',
        'status',
        'sold_at',
        'subtotal',
        'items_discount',
        'discount',
        'total',
        'paid_amount',
        'returned_amount',
        'due_amount',
        'payment_status',
        'payment_method',
        'tendered_amount',
        'change_amount',
        'cost_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sale_type' => SaleType::class,
            'status' => SaleStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'sold_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'items_discount' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'tendered_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Sales cannot be deleted.'));
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * @return HasMany<SaleReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /**
     * @return MorphMany<PaymentAllocation, $this>
     */
    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }
}
