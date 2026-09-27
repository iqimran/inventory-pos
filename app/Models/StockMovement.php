<?php

namespace App\Models;

use App\Enums\AdjustmentReason;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Immutable stock ledger entry. Create only through App\Domain\Inventory\StockService;
 * mistakes are corrected with a compensating movement, never by editing or deleting.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'type',
        'quantity',
        'balance_after',
        'unit_cost',
        'reference_type',
        'reference_id',
        'reason',
        'notes',
        'occurred_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'reason' => AdjustmentReason::class,
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'unit_cost' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Stock movements cannot be deleted.'));
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
