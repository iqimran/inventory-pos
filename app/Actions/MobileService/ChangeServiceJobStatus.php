<?php

namespace App\Actions\MobileService;

use App\Domain\MobileService\EstimateCharge;
use App\Domain\MobileService\ServiceJobGuard;
use App\Enums\ServiceJobStatus;
use App\Models\ServiceJob;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a job through its workflow, enforcing the transition map and the business guards:
 * - WAITING_FOR_APPROVAL needs a diagnosis (the customer approves a diagnosed estimate);
 * - approval (WAITING_FOR_APPROVAL → IN_PROGRESS) records the approved amount;
 * - DELIVERED needs the job invoiced (money owed is on the customer's ledger);
 * - CANCELLED needs a reason and is impossible once invoiced (void is a separate, explicit process);
 * - an invoiced job can only be delivered.
 * Every change is logged with the user and time.
 */
class ChangeServiceJobStatus
{
    public function __construct(
        private readonly ServiceJobGuard $guard,
        private readonly EstimateCharge $estimateCharge,
    ) {}

    /**
     * @param  array{notes?: ?string, diagnosis?: ?string, estimated_amount?: ?string, approved_amount?: ?string, reason?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(ServiceJob $job, ServiceJobStatus $to, array $data = []): ServiceJob
    {
        return DB::transaction(function () use ($job, $to, $data): ServiceJob {
            $job = $this->guard->lock($job);
            $from = $job->status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => "A job that is {$from->label()} cannot be moved to {$to->label()}."]);
            }

            $invoiced = $this->guard->isInvoiced($job);

            if ($invoiced && $to !== ServiceJobStatus::Delivered) {
                throw ValidationException::withMessages(['status' => "Job {$job->job_no} has been invoiced; it can only be delivered."]);
            }

            $changes = ['status' => $to];

            match ($to) {
                ServiceJobStatus::WaitingForApproval => $changes += $this->forApproval($job, $data),
                ServiceJobStatus::InProgress => $changes += $from === ServiceJobStatus::WaitingForApproval ? $this->approval($data) : [],
                ServiceJobStatus::Delivered => $changes += $this->delivery($job, $invoiced),
                ServiceJobStatus::Cancelled => $changes += $this->cancellation($data),
                default => null,
            };

            $job->update($changes);

            if ($job->wasChanged('estimated_amount')) {
                $this->estimateCharge->sync($job);
            }

            $job->statusLogs()->create([
                'from_status' => $from,
                'to_status' => $to,
                'notes' => $to === ServiceJobStatus::Cancelled ? $data['reason'] : ($data['notes'] ?? null),
                'created_by' => Auth::id(),
            ]);

            return $job;
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function forApproval(ServiceJob $job, array $data): array
    {
        $diagnosis = trim((string) ($data['diagnosis'] ?? $job->diagnosis ?? ''));

        if ($diagnosis === '') {
            throw ValidationException::withMessages(['diagnosis' => 'Record the diagnosis before asking the customer for approval.']);
        }

        $changes = ['diagnosis' => $diagnosis];

        if (isset($data['estimated_amount']) && $data['estimated_amount'] !== '') {
            $changes['estimated_amount'] = Money::of($data['estimated_amount']);
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function approval(array $data): array
    {
        if (! isset($data['approved_amount']) || $data['approved_amount'] === '') {
            throw ValidationException::withMessages(['approved_amount' => 'Enter the amount the customer approved.']);
        }

        return ['approved_amount' => Money::of($data['approved_amount']), 'approved_at' => now()];
    }

    /**
     * @return array<string, mixed>
     */
    private function delivery(ServiceJob $job, bool $invoiced): array
    {
        if (! $invoiced) {
            throw ValidationException::withMessages(['status' => "Invoice job {$job->job_no} before delivering the device."]);
        }

        return ['delivered_at' => now()];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancellation(array $data): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for cancelling the job.']);
        }

        return ['cancelled_at' => now(), 'cancel_reason' => $reason];
    }
}
