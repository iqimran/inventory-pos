<?php

namespace App\Domain\MobileService;

use App\Models\ServiceJob;
use App\Support\Money;

/**
 * Bills a job's estimate as an editable SERVICE line, so the draft total and the invoice include it:
 *
 * - a job with no parts or charges gets an "Estimated service charge" line when an estimate is set;
 * - the line follows the estimate (removed when the estimate is cleared);
 * - it is a placeholder: adding a real part or charge removes it, because the estimate quotes the
 *   whole job and would otherwise be billed twice;
 * - edited by hand, it becomes an ordinary charge and stays.
 *
 * Call inside the transaction that holds the job lock; finished or invoiced jobs are left alone.
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

        if ($line) {
            Money::isPositive($estimate) ? $line->update(['amount' => $estimate]) : $line->delete();
        } elseif (Money::isPositive($estimate) && ! $job->charges()->exists() && ! $job->items()->exists()) {
            $job->charges()->create(['description' => self::DESCRIPTION, 'amount' => $estimate, 'is_estimate' => true]);
        } else {
            return;
        }

        $this->refreshTotal($job);
    }

    /**
     * Real lines are being billed: drop the placeholder estimate line.
     */
    public function release(ServiceJob $job): void
    {
        if ($job->charges()->where('is_estimate', true)->delete() > 0) {
            $this->refreshTotal($job);
        }
    }

    private function refreshTotal(ServiceJob $job): void
    {
        $job->update(['service_charge' => Money::of((string) $job->charges()->sum('amount'))]);
    }
}
