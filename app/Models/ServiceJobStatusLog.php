<?php

namespace App\Models;

use App\Enums\ServiceJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable record of a service job status change.
 */
class ServiceJobStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['service_job_id', 'from_status', 'to_status', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'from_status' => ServiceJobStatus::class,
            'to_status' => ServiceJobStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Status history is immutable.'));
        static::deleting(fn () => throw new LogicException('Status history cannot be deleted.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
