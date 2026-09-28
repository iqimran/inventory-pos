<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's handset brought in for service.
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasUserstamps;

    protected $fillable = [
        'party_id',
        'brand',
        'model',
        'imei1',
        'imei2',
        'serial_no',
        'color',
        'notes',
    ];

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasMany<ServiceJob, $this>
     */
    public function serviceJobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function displayName(): string
    {
        return trim("{$this->brand} {$this->model}");
    }
}
