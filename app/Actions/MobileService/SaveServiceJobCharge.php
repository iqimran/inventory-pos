<?php

namespace App\Actions\MobileService;

use App\Domain\MobileService\ServiceJobGuard;
use App\Models\ServiceJob;
use App\Models\ServiceJobCharge;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes or removes a service / labour charge on an open, un-invoiced job and keeps the
 * job's service_charge total in step. Changing or removing the estimate line changes or clears the
 * job's estimate (see EstimateCharge). Service charges are SERVICE revenue and never touch stock.
 */
class SaveServiceJobCharge
{
    public function __construct(private readonly ServiceJobGuard $guard) {}

    /**
     * @param  array{description: string, amount: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(ServiceJob $job, array $data, ?ServiceJobCharge $charge = null): ServiceJobCharge
    {
        return DB::transaction(function () use ($job, $data, $charge): ServiceJobCharge {
            $job = $this->guard->lockForBilling($job);
            $attributes = ['description' => trim($data['description']), 'amount' => Money::of($data['amount'])];

            if ($charge) {
                $charge->update($attributes);

                // The estimate line is the estimate: keep the two equal.
                if ($charge->is_estimate) {
                    $job->update(['estimated_amount' => $attributes['amount']]);
                }
            } else {
                $charge = $job->charges()->create($attributes);
            }

            $this->refreshTotal($job);

            return $charge;
        }, 3);
    }

    /**
     * @throws ValidationException
     */
    public function remove(ServiceJobCharge $charge): void
    {
        DB::transaction(function () use ($charge): void {
            $job = $this->guard->lockForBilling($charge->service_job_id);

            $charge->refresh()->delete();

            if ($charge->is_estimate) {
                $job->update(['estimated_amount' => '0.00']);
            }

            $this->refreshTotal($job);
        }, 3);
    }

    private function refreshTotal(ServiceJob $job): void
    {
        $job->update(['service_charge' => Money::of((string) $job->charges()->sum('amount'))]);
    }
}
