<?php

namespace App\Http\Controllers\Service;

use App\Actions\MobileService\SaveServiceJobCharge;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\ServiceJobChargeRequest;
use App\Models\ServiceJob;
use App\Models\ServiceJobCharge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ServiceJobChargeController extends Controller
{
    public function store(ServiceJobChargeRequest $request, ServiceJob $serviceJob, SaveServiceJobCharge $saveCharge): RedirectResponse
    {
        $saveCharge->handle($serviceJob, $request->chargeData());

        return back()->with('success', 'Service charge added.');
    }

    public function update(ServiceJobChargeRequest $request, ServiceJob $serviceJob, ServiceJobCharge $charge, SaveServiceJobCharge $saveCharge): RedirectResponse
    {
        $saveCharge->handle($serviceJob, $request->chargeData(), $charge);

        return back()->with('success', 'Service charge updated.');
    }

    public function destroy(ServiceJob $serviceJob, ServiceJobCharge $charge, SaveServiceJobCharge $saveCharge): RedirectResponse
    {
        Gate::authorize('update', $serviceJob);

        $saveCharge->remove($charge);

        return back()->with('success', 'Service charge removed.');
    }
}
