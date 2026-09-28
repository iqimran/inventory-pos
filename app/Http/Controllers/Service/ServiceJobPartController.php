<?php

namespace App\Http\Controllers\Service;

use App\Actions\MobileService\RemoveServiceJobPart;
use App\Actions\MobileService\SaveServiceJobPart;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\ServiceJobPartRequest;
use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ServiceJobPartController extends Controller
{
    public function store(ServiceJobPartRequest $request, ServiceJob $serviceJob, SaveServiceJobPart $savePart): RedirectResponse
    {
        $savePart->handle($serviceJob, $request->partData(), null, $request->user()->can('overridePrice', ServiceJob::class));

        return back()->with('success', 'Part added.');
    }

    public function update(ServiceJobPartRequest $request, ServiceJob $serviceJob, ServiceJobItem $item, SaveServiceJobPart $savePart): RedirectResponse
    {
        $savePart->handle($serviceJob, $request->partData(), $item, $request->user()->can('overridePrice', ServiceJob::class));

        return back()->with('success', 'Part updated.');
    }

    public function destroy(ServiceJob $serviceJob, ServiceJobItem $item, RemoveServiceJobPart $removePart): RedirectResponse
    {
        Gate::authorize('update', $serviceJob);

        $removePart->handle($item);

        return back()->with('success', 'Part removed.');
    }
}
