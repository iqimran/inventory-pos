<?php

namespace App\Models;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Models\Concerns\HasUserstamps;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class Payment extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'payment_no',
        'party_id',
        'direction',
        'purpose',
        'method',
        'amount',
        'allocated_amount',
        'reference_no',
        'paid_at',
        'notes',
        'source_type',
        'source_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'purpose' => PaymentPurpose::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Payments cannot be deleted.'));
    }

    public function unallocatedAmount(): string
    {
        return Money::sub($this->amount, $this->allocated_amount);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
