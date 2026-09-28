<?php

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\RecordPurchasePayment;
use App\Actions\Purchasing\RecordSupplierAdvance;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Support\Money;
use App\Support\OrganizationProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Payment::class);

        $filters = $request->validate([
            'party_id' => ['nullable', 'integer'],
            'purpose' => ['nullable', Rule::enum(PaymentPurpose::class)],
        ]);

        $payments = Payment::query()
            ->with(['party:id,name,phone', 'creator:id,name'])
            ->when($filters['party_id'] ?? null, fn ($q, $id) => $q->where('party_id', $id))
            ->when($filters['purpose'] ?? null, fn ($q, $purpose) => $q->where('purpose', $purpose))
            ->latest('paid_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('supplier-payments/index', [
            'payments' => PaymentResource::collection($payments),
            'filters' => ['party_id' => $filters['party_id'] ?? null, 'purpose' => $filters['purpose'] ?? ''],
            'suppliers' => Party::query()->suppliers()->orderBy('name')->get(['id', 'name']),
            'purposes' => array_map(fn (PaymentPurpose $p) => ['value' => $p->value, 'label' => $p->label()], PaymentPurpose::cases()),
        ]);
    }

    public function create(Request $request, PaymentAllocator $allocator): Response
    {
        Gate::authorize('create', Payment::class);

        $mode = $request->query('mode') === 'advance' ? 'advance' : 'payment';
        $supplier = $request->integer('party_id')
            ? Party::query()->suppliers()->active()->find($request->integer('party_id'))
            : null;

        return Inertia::render('supplier-payments/create', [
            'mode' => $mode,
            'suppliers' => Party::query()->suppliers()->active()->orderBy('name')->get(['id', 'name', 'phone']),
            'methods' => PaymentMethod::options(),
            'supplier' => $supplier ? [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'balance' => $supplier->balance,
                'payable' => Money::max('0.00', Money::negate(Money::of($supplier->balance))),
                'available_advance' => $allocator->availableAdvance($supplier),
                'due_purchases' => Purchase::query()
                    ->where('party_id', $supplier->id)
                    ->where('due_amount', '>', 0)
                    ->orderBy('purchase_date')
                    ->orderBy('id')
                    ->get(['id', 'purchase_no', 'purchase_date', 'total', 'due_amount'])
                    ->map(fn (Purchase $p) => [
                        'id' => $p->id,
                        'purchase_no' => $p->purchase_no,
                        'purchase_date' => $p->purchase_date->toDateString(),
                        'total' => $p->total,
                        'due_amount' => $p->due_amount,
                    ]),
            ] : null,
            'purchaseId' => $request->integer('purchase_id') ?: null,
        ]);
    }

    public function storePayment(SupplierPaymentRequest $request, RecordPurchasePayment $recordPayment): RedirectResponse
    {
        $payment = $recordPayment->handle(
            party: Party::findOrFail($request->integer('party_id')),
            amount: (string) $request->validated('amount'),
            method: PaymentMethod::from($request->validated('method')),
            date: $request->validated('date'),
            purchase: $request->filled('purchase_id') ? Purchase::findOrFail($request->integer('purchase_id')) : null,
            referenceNo: $request->validated('reference_no'),
            notes: $request->validated('notes'),
        );

        return to_route('supplier-payments.show', $payment)->with('success', "Payment {$payment->payment_no} recorded.");
    }

    public function storeAdvance(SupplierPaymentRequest $request, RecordSupplierAdvance $recordAdvance): RedirectResponse
    {
        $payment = $recordAdvance->handle(
            party: Party::findOrFail($request->integer('party_id')),
            amount: (string) $request->validated('amount'),
            method: PaymentMethod::from($request->validated('method')),
            date: $request->validated('date'),
            referenceNo: $request->validated('reference_no'),
            notes: $request->validated('notes'),
        );

        return to_route('supplier-payments.show', $payment)->with('success', "Advance {$payment->payment_no} recorded.");
    }

    public function show(Payment $payment): Response
    {
        Gate::authorize('view', $payment);

        return Inertia::render('supplier-payments/show', [
            'payment' => new PaymentResource($payment->load(['party', 'allocations.allocatable', 'creator:id,name'])),
        ]);
    }

    /**
     * Printable payment voucher with the organization header.
     */
    public function print(Payment $payment, OrganizationProfile $organization): Response
    {
        Gate::authorize('view', $payment);

        return Inertia::render('supplier-payments/print', [
            'payment' => new PaymentResource($payment->load(['party', 'allocations.allocatable', 'creator:id,name'])),
            'shop' => $organization->details(),
        ]);
    }
}
