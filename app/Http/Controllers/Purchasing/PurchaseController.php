<?php

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\ApplyAdvanceToPurchase;
use App\Actions\Purchasing\CreatePurchase;
use App\Domain\Inventory\ProductSearch;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PurchaseResource;
use App\Models\Party;
use App\Models\Purchase;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Purchase::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'party_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $purchases = Purchase::query()
            ->with('party:id,name,phone')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('purchase_no', $term)->orWhere('supplier_invoice_no', $term)))
            ->when($filters['party_id'] ?? null, fn ($q, $id) => $q->where('party_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('payment_status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('purchase_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('purchase_date', '<=', $to))
            ->latest('purchase_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('purchases/index', [
            'purchases' => PurchaseResource::collection($purchases),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'party_id' => $filters['party_id'] ?? null,
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'suppliers' => Party::query()->suppliers()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request, ProductSearch $search, PaymentAllocator $allocator): Response
    {
        Gate::authorize('create', Purchase::class);

        $term = trim((string) $request->query('q', ''));
        $supplier = $request->integer('party_id')
            ? Party::query()->suppliers()->active()->find($request->integer('party_id'))
            : null;

        return Inertia::render('purchases/create', [
            'suppliers' => Party::query()->suppliers()->active()->orderBy('name')->get(['id', 'name', 'phone']),
            'methods' => PaymentMethod::options(),
            'supplier' => $supplier ? [
                'id' => $supplier->id,
                'balance' => $supplier->balance,
                'available_advance' => $allocator->availableAdvance($supplier),
            ] : null,
            'results' => $term === ''
                ? []
                : ProductResource::collection($search->query(['q' => $term, 'status' => 'active'])->limit(10)->get()),
            'q' => $term,
        ]);
    }

    public function store(PurchaseRequest $request, CreatePurchase $createPurchase): RedirectResponse
    {
        $purchase = $createPurchase->handle($request->purchaseData());

        return to_route('purchases.show', $purchase)->with('success', "Purchase {$purchase->purchase_no} recorded.");
    }

    public function show(Purchase $purchase, PaymentAllocator $allocator): Response
    {
        Gate::authorize('view', $purchase);

        $purchase->load(['party', 'items.product:id,name,sku', 'returns', 'allocations.payment', 'creator:id,name']);

        return Inertia::render('purchases/show', [
            'purchase' => new PurchaseResource($purchase),
            'applicableAdvance' => Money::isPositive(Money::of($purchase->due_amount))
                ? Money::min(Money::of($purchase->due_amount), $allocator->availableAdvance($purchase->party, Money::of($purchase->due_amount)))
                : '0.00',
        ]);
    }

    public function applyAdvance(Purchase $purchase, ApplyAdvanceToPurchase $applyAdvance): RedirectResponse
    {
        Gate::authorize('pay', $purchase);

        $applied = $applyAdvance->handle($purchase);

        return back()->with('success', "Applied {$applied} of supplier advance to {$purchase->purchase_no}.");
    }
}
