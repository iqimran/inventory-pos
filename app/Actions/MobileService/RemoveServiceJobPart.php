<?php

namespace App\Actions\MobileService;

use App\Domain\MobileService\ServiceJobGuard;
use App\Models\ServiceJobItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a draft part from an open, un-invoiced job. Nothing was consumed, so no stock
 * movement is involved; consumed (invoiced) parts are locked and cannot be removed.
 */
class RemoveServiceJobPart
{
    public function __construct(private readonly ServiceJobGuard $guard) {}

    /**
     * @throws ValidationException
     */
    public function handle(ServiceJobItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $this->guard->lockForBilling($item->service_job_id);

            $item->refresh()->delete();
        }, 3);
    }
}
