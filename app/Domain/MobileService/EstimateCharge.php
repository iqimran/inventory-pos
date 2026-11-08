<?php

namespace App\Domain\MobileService;

use App\Models\ServiceJob;
use App\Support\Money;

/**
 * Bills a job's estimate as a SERVICE line ("Estimated service charge"), in addition to the job's
 * parts and other charges, so the draft total and the invoice include it.
 *
 * While the job is open the line and the estimate are the same figure: changing the estimate
 * changes the line, and editing or deleting the line changes or clears the estimate
 * (SaveServiceJobCharge). Call inside the transaction that holds the job lock; finished or
 * invoiced jobs are left alone.
 */
class EstimateCharge
{
    public const DESCRIPTION = 'Estimated service charge';

    public function __construct(private readonly ServiceJobGuard $guard) {}

    public function sync(ServiceJob $job): void
    {
        if ($job->status->isTerminal() || $this->guard->isInvoiced($job)) {
            return;
        }

        $estimate = Money::of((string) $job->estimated_amount);
        $line = $job->charges()->where('is_estimate', true)->first();

        if ($line && Money::isPositive($estimate)) {
            $line->update(['amount' => $estimate]);
        } elseif ($line) {
            $line->delete();
        } elseif (Money::isPositive($estimate)) {
            $job->charges()->create(['description' => self::DESCRIPTION, 'amount' => $estimate, 'is_estimate' => true]);
        }

        $job->update(['service_charge' => Money::of((string) $job->charges()->sum('amount'))]);
    }
}
