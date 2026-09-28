<?php

namespace App\Http\Controllers\Service;

use App\Actions\MobileService\SaveDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\DeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Device::class);

        $filters = $request->validate(['q' => ['nullable', 'string', 'max:64']]);
        $term = trim($filters['q'] ?? '');

        $devices = Device::query()
            ->with('party:id,name,phone')
            ->withCount('serviceJobs')
            ->when($term !== '', fn (Builder $query) => $this->search($query, $term))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('service/devices/index', [
            'devices' => DeviceResource::collection($devices),
            'filters' => ['q' => $term],
        ]);
    }

    public function store(DeviceRequest $request, SaveDevice $saveDevice): RedirectResponse
    {
        $device = $saveDevice->handle(null, $request->validated());

        return back()->with('success', "{$device->displayName()} registered.");
    }

    public function update(DeviceRequest $request, Device $device, SaveDevice $saveDevice): RedirectResponse
    {
        $saveDevice->handle($device, $request->validated());

        return back()->with('success', "{$device->displayName()} updated.");
    }

    /**
     * A customer's devices, for job intake.
     */
    public function forCustomer(Party $party): JsonResponse
    {
        Gate::authorize('viewAny', Device::class);

        return response()->json(['data' => DeviceResource::collection($party->devices()->latest('id')->get())]);
    }

    /**
     * @param  Builder<Device>  $query
     */
    private function search(Builder $query, string $term): void
    {
        $digits = preg_replace('/\D+/', '', $term);
        $contains = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(function (Builder $match) use ($term, $digits, $contains) {
            $match->where('serial_no', strtoupper($term))
                ->orWhereRaw("brand LIKE ? ESCAPE '!'", [$contains])
                ->orWhereRaw("model LIKE ? ESCAPE '!'", [$contains]);

            if (strlen($digits) >= 6) {
                $match->orWhere('imei1', 'like', "%{$digits}%")
                    ->orWhere('imei2', 'like', "%{$digits}%")
                    ->orWhereHas('party', fn (Builder $party) => $party->where('phone', 'like', "%{$digits}%"));
            }
        });
    }
}
