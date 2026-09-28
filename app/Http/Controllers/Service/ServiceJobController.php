<?php

namespace App\Http\Controllers\Service;

use App\Actions\MobileService\CreateServiceJob;
use App\Actions\MobileService\UpdateServiceJob;
use App\Domain\MobileService\TechnicianDirectory;
use App\Domain\Sales\CustomerDirectory;
use App\Enums\PaymentMethod;
use App\Enums\ServiceJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\ServiceJobRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\ServiceJobResource;
use App\Models\ServiceJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceJobController extends Controller
{
    public function index(Request $request, TechnicianDirectory $technicians): Response
    {
        Gate::authorize('viewAny', ServiceJob::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(ServiceJobStatus::class)],
            'technician_id' => ['nullable', 'integer'],
        ]);
        $term = trim($filters['q'] ?? '');

        $jobs = ServiceJob::query()
            ->with(['party:id,name,phone,balance', 'device', 'technician:id,name', 'invoice:id,service_job_id,invoice_no,total,due_amount,payment_status'])
            ->when($term !== '', fn (Builder $query) => $this->search($query, $term))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['technician_id'] ?? null, fn (Builder $q, $id) => $q->where('technician_id', $id))
            ->latest('received_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('service/jobs/index', [
            'jobs' => ServiceJobResource::collection($jobs),
            'statuses' => ServiceJobStatus::options(),
            'technicians' => $technicians->options(),
            'filters' => [
                'q' => $term,
                'status' => $filters['status'] ?? '',
                'technician_id' => $filters['technician_id'] ?? '',
            ],
        ]);
    }

    public function create(Request $request, CustomerDirectory $directory, TechnicianDirectory $technicians): Response
    {
        Gate::authorize('create', ServiceJob::class);

        $customer = $request->integer('party_id') ? $directory->customers()->find($request->integer('party_id')) : null;

        return Inertia::render('service/jobs/create', [
            'technicians' => $technicians->options(),
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone, 'balance' => $customer->balance] : null,
            'devices' => $customer ? DeviceResource::collection($customer->devices()->latest('id')->get()) : [],
        ]);
    }

    public function store(ServiceJobRequest $request, CreateServiceJob $createJob): RedirectResponse
    {
        $job = $createJob->handle($request->jobData());

        return to_route('service-jobs.show', $job)->with('success', "Job {$job->job_no} opened.");
    }

    public function show(Request $request, ServiceJob $serviceJob, TechnicianDirectory $technicians): Response
    {
        Gate::authorize('view', $serviceJob);

        $serviceJob->load([
            'party',
            'device',
            'technician:id,name',
            'items' => fn ($query) => $query->with(['product:id,name,sku', 'product.stock'])->orderBy('id'),
            'charges' => fn ($query) => $query->orderBy('id'),
            'invoice',
            'statusLogs' => fn ($query) => $query->with('creator:id,name')->orderBy('id'),
            'creator:id,name',
        ]);

        return Inertia::render('service/jobs/show', [
            'job' => new ServiceJobResource($serviceJob),
            'technicians' => $technicians->options(),
            'methods' => PaymentMethod::options(),
            'canOverridePrice' => $request->user()->can('overridePrice', ServiceJob::class),
        ]);
    }

    public function update(ServiceJobRequest $request, ServiceJob $serviceJob, UpdateServiceJob $updateJob): RedirectResponse
    {
        $updateJob->handle($serviceJob, $request->jobData());

        return back()->with('success', "Job {$serviceJob->job_no} updated.");
    }

    /**
     * Job number, customer phone, IMEI or serial number.
     *
     * @param  Builder<ServiceJob>  $query
     */
    private function search(Builder $query, string $term): void
    {
        $digits = preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $match) use ($term, $digits) {
            $match->where('job_no', strtoupper($term))
                ->orWhereHas('device', fn (Builder $device) => $device->where('serial_no', strtoupper($term)));

            if (strlen($digits) >= 6) {
                $match->orWhereHas('device', fn (Builder $device) => $device->where('imei1', 'like', "%{$digits}%")->orWhere('imei2', 'like', "%{$digits}%"))
                    ->orWhereHas('party', fn (Builder $party) => $party->where('phone', 'like', "%{$digits}%"));
            }
        });
    }
}
