<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part planned for / used on a service job. It does not touch stock until the job is
 * invoiced; consumed_at / stock_movement_id / cost_snapshot are set at that moment.
 */
class ServiceJobItem extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'service_job_id',
        'product_id',
        'quantity',
        'list_price',
        'unit_price',
        'price_overridden',
        'line_total',
        'cost_snapshot',
        'consumed_at',
        'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'list_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'price_overridden' => 'boolean',
            'line_total' => 'decimal:2',
            'cost_snapshot' => 'decimal:2',
            'consumed_at' => 'datetime',
        ];
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
