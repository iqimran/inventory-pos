<?php

namespace App\Actions\MobileService;

use App\Domain\MobileService\ServiceJobGuard;
use App\Models\ServiceJob;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Updates a job's working details (technician, complaint, diagnosis, estimate, promise date, notes).
 * Status, approval and money lines have their own actions.
 */
class UpdateServiceJob
{
    public function __construct(private readonly ServiceJobGuard $guard) {}

    /**
     * @param  array{technician_id?: ?int, complaint: string, diagnosis?: ?string, estimated_amount?: ?string,
     *               promised_at?: ?string, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(ServiceJob $job, array $data): ServiceJob
    {
        return DB::transaction(function () use ($job, $data): ServiceJob {
            $job = $this->guard->lock($job);

            if ($job->status->isTerminal()) {
                throw ValidationException::withMessages(['job' => "Job {$job->job_no} is {$job->status->label()} and can no longer be changed."]);
            }

            $job->update([
                'technician_id' => $data['technician_id'] ?? null,
                'complaint' => $data['complaint'],
                'diagnosis' => $data['diagnosis'] ?? null,
                'estimated_amount' => Money::of($data['estimated_amount'] ?? '0'),
                'promised_at' => $data['promised_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            return $job;
        });
    }
}
