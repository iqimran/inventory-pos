<?php

namespace App\Http\Controllers\Service;

use App\Actions\MobileService\CreateServiceInvoice;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\ServiceInvoiceRequest;
use App\Http\Resources\ServiceInvoiceResource;
use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ServiceInvoice::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
        ]);

        $invoices = ServiceInvoice::query()
            ->with(['party:id,name,phone,address', 'serviceJob'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('invoice_no', strtoupper(trim($term))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('payment_status', $status))
            ->latest('invoiced_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('service/invoices/index', [
            'invoices' => ServiceInvoiceResource::collection($invoices),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
        ]);
    }

    public function store(ServiceInvoiceRequest $request, ServiceJob $serviceJob, CreateServiceInvoice $createInvoice): RedirectResponse
    {
        $invoice = $createInvoice->handle($serviceJob, $request->invoiceData());

        return to_route('service-invoices.show', $invoice)->with('success', "Service invoice {$invoice->invoice_no} created.");
    }

    public function show(ServiceInvoice $serviceInvoice): Response
    {
        Gate::authorize('view', $serviceInvoice);

        return Inertia::render('service/invoices/show', [
            'invoice' => new ServiceInvoiceResource($serviceInvoice->load([
                'party',
                'serviceJob.device',
                'items' => fn ($query) => $query->with('product:id,sku')->orderBy('id'),
                'allocations.payment',
                'creator:id,name',
            ])),
        ]);
    }
}
