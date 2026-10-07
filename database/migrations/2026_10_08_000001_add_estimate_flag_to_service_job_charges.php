<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A job's estimate is billed as an editable service charge line ("Estimated service charge"),
         * kept in step with the estimate until someone edits the line by hand.
         */
        Schema::table('service_job_charges', function (Blueprint $table) {
            $table->boolean('is_estimate')->default(false)->after('amount');
        });

        // Open, un-invoiced jobs that have an estimate but no charge yet get their estimate line now.
        $jobs = DB::table('service_jobs')
            ->where('estimated_amount', '>', 0)
            ->whereNotIn('status', ['DELIVERED', 'CANCELLED'])
            ->whereNotExists(fn ($q) => $q->from('service_invoices')->whereColumn('service_invoices.service_job_id', 'service_jobs.id'))
            ->whereNotExists(fn ($q) => $q->from('service_job_charges')->whereColumn('service_job_charges.service_job_id', 'service_jobs.id'))
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
            DB::table('service_jobs')->where('id', $job->id)->update(['service_charge' => $job->estimated_amount]);
        }
    }

    public function down(): void
    {
        Schema::table('service_job_charges', function (Blueprint $table) {
            $table->dropColumn('is_estimate');
        });
    }
};
