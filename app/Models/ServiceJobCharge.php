<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service / labour charge on a job. SERVICE revenue: never a stock movement.
 */
class ServiceJobCharge extends Model
{
    use HasUserstamps;

    protected $fillable = ['service_job_id', 'description', 'amount', 'is_estimate'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_estimate' => 'boolean'];
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class);
    }
}
