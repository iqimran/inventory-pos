<?php

namespace App\Domain\MobileService;

use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Serialises changes to one service job: the job row is locked for the rest of the current
 * transaction, so parts/charges cannot change while the job is being invoiced or moved.
 */
class ServiceJobGuard
{
    public function lock(ServiceJob|int $job): ServiceJob
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Service jobs must be locked inside a database transaction.');
        }

        $jobId = $job instanceof ServiceJob ? $job->getKey() : $job;

        return ServiceJob::whereKey($jobId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock the job and ensure its parts and charges may still change (open and not yet invoiced).
     *
     * @throws ValidationException
     */
    public function lockForBilling(ServiceJob|int $job): ServiceJob
    {
        $job = $this->lock($job);

        if ($job->status->isTerminal()) {
            throw ValidationException::withMessages(['job' => "Job {$job->job_no} is {$job->status->label()} and can no longer be changed."]);
        }

        if ($this->isInvoiced($job)) {
            throw ValidationException::withMessages(['job' => "Job {$job->job_no} has been invoiced; its parts and charges are final."]);
        }

        return $job;
    }

    public function isInvoiced(ServiceJob $job): bool
    {
        return ServiceInvoice::where('service_job_id', $job->id)->exists();
    }
}
