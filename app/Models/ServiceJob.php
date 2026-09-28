<?php

namespace App\Models;

use App\Enums\ServiceJobStatus;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A repair job for a customer's device. Status changes go through
 * App\Actions\MobileService\ChangeServiceJobStatus; abandoned jobs are CANCELLED, never deleted.
 */
class ServiceJob extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'job_no',
        'party_id',
        'device_id',
        'technician_id',
        'complaint',
        'diagnosis',
        'estimated_amount',
        'approved_amount',
        'service_charge',
        'status',
        'received_at',
        'promised_at',
        'approved_at',
        'delivered_at',
        'cancelled_at',
        'cancel_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceJobStatus::class,
            'estimated_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'received_at' => 'datetime',
            'promised_at' => 'datetime',
            'approved_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Service jobs cannot be deleted; cancel them instead.'));
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return HasMany<ServiceJobItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ServiceJobItem::class);
    }

    /**
     * @return HasMany<ServiceJobCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(ServiceJobCharge::class);
    }

    /**
     * @return HasMany<ServiceJobStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(ServiceJobStatusLog::class);
    }

    /**
     * @return HasOne<ServiceInvoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(ServiceInvoice::class);
    }
}
