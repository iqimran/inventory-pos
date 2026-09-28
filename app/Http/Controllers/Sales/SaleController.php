<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CreateSale;
use App\Enums\PaymentStatus;
use App\Enums\SaleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Support\OrganizationProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Sale::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'sale_type' => ['nullable', Rule::enum(SaleType::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'party_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $sales = Sale::query()
            ->with(['party:id,name,phone,address', 'creator:id,name'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('invoice_no', strtoupper($term)))
            ->when($filters['sale_type'] ?? null, fn ($q, $type) => $q->where('sale_type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('payment_status', $status))
            ->when($filters['party_id'] ?? null, fn ($q, $id) => $q->where('party_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('sold_at', '>=', now()->parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('sold_at', '<=', now()->parse($to)->endOfDay()))
            ->latest('sold_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('sales/index', [
            'sales' => SaleResource::collection($sales),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'sale_type' => $filters['sale_type'] ?? '',
                'status' => $filters['status'] ?? '',
                'party_id' => $filters['party_id'] ?? null,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    public function store(SaleRequest $request, CreateSale $createSale): RedirectResponse
    {
        $sale = $createSale->handle($request->saleData(), $request->user()->can('overridePrice', Sale::class));

        return to_route('sales.receipt', ['sale' => $sale, 'new' => 1])->with('success', "Sale {$sale->invoice_no} completed.");
    }

    public function show(Sale $sale): Response
    {
        Gate::authorize('view', $sale);

        return Inertia::render('sales/show', [
            'sale' => new SaleResource($sale->load([
                'party',
                'items' => fn ($query) => $query->with('product:id,name,sku')->withSum('returnItems as returned_quantity', 'quantity'),
                'returns',
                'allocations.payment',
                'creator:id,name',
            ])),
        ]);
    }

    /**
     * Printable customer sale slip.
     */
    public function receipt(Request $request, Sale $sale, OrganizationProfile $organization): Response
    {
        Gate::authorize('view', $sale);

        return Inertia::render('sales/receipt', [
            'sale' => new SaleResource($sale->load(['party', 'items.product:id,name,sku', 'allocations.payment', 'creator:id,name'])),
            'shop' => $organization->details(),
            'justCompleted' => $request->boolean('new'),
        ]);
    }
}
