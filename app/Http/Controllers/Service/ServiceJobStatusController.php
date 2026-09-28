<?php

namespace App\Http\Controllers\Service;

use App\Actions\MobileService\ChangeServiceJobStatus;
use App\Enums\ServiceJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\ServiceJobStatusRequest;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;

class ServiceJobStatusController extends Controller
{
    public function store(ServiceJobStatusRequest $request, ServiceJob $serviceJob, ChangeServiceJobStatus $changeStatus): RedirectResponse
    {
        $job = $changeStatus->handle($serviceJob, ServiceJobStatus::from($request->validated('status')), $request->transitionData());

        return back()->with('success', "Job {$job->job_no} is now {$job->status->label()}.");
    }
}
