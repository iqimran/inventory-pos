<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The estimate is billed in addition to a job's parts and other charges: open, un-invoiced jobs
     * with an estimate but no estimate line (because they already had charges) get one now.
     */
    public function up(): void
    {
        $jobs = DB::table('service_jobs')
            ->where('estimated_amount', '>', 0)
            ->whereNotIn('status', ['DELIVERED', 'CANCELLED'])
            ->whereNotExists(fn ($q) => $q->from('service_invoices')->whereColumn('service_invoices.service_job_id', 'service_jobs.id'))
            ->whereNotExists(fn ($q) => $q->from('service_job_charges')->whereColumn('service_job_charges.service_job_id', 'service_jobs.id')->where('is_estimate', true))
            ->get(['id', 'estimated_amount']);

        foreach ($jobs as $job) {
            DB::table('service_job_charges')->insert([
                'service_job_id' => $job->id,
                'description' => 'Estimated service charge',
                'amount' => $job->estimated_amount,
                'is_estimate' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $total = DB::table('service_job_charges')->where('service_job_id', $job->id)->sum('amount');
            DB::table('service_jobs')->where('id', $job->id)->update(['service_charge' => $total]);
        }
    }

    public function down(): void
    {
        // Data only: estimate lines added here stay (they are ordinary, editable charge lines).
    }
};
