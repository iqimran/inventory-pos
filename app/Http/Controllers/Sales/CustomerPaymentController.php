<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CollectSalePayment;
use App\Domain\Sales\CustomerDirectory;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CustomerPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPaymentController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Sale::class);

        $payments = Payment::query()
            ->with(['party:id,name,phone', 'creator:id,name'])
            ->where('purpose', PaymentPurpose::SalePayment)
            ->latest('paid_at')
            ->latest('id')
            ->paginate(25);

        return Inertia::render('customer-payments/index', ['payments' => PaymentResource::collection($payments)]);
    }

    public function create(Request $request, CustomerDirectory $directory): Response
    {
        Gate::authorize('collect', Sale::class);

        $customer = $request->integer('party_id')
            ? $directory->customers()->find($request->integer('party_id'))
            : null;

        return Inertia::render('customer-payments/create', [
            'methods' => PaymentMethod::options(),
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'balance' => $customer->balance,
                'receivable' => Money::max('0.00', Money::of($customer->balance)),
                'due_sales' => Sale::query()
                    ->where('party_id', $customer->id)
                    ->where('due_amount', '>', 0)
                    ->orderBy('sold_at')
                    ->orderBy('id')
                    ->get(['id', 'invoice_no', 'sold_at', 'total', 'due_amount'])
                    ->map(fn (Sale $sale) => [
                        'id' => $sale->id,
                        'invoice_no' => $sale->invoice_no,
                        'sold_at' => $sale->sold_at->toIso8601String(),
                        'total' => $sale->total,
                        'due_amount' => $sale->due_amount,
                    ]),
                'due_service_invoices' => ServiceInvoice::query()
                    ->where('party_id', $customer->id)
                    ->where('due_amount', '>', 0)
                    ->orderBy('invoiced_at')
                    ->orderBy('id')
                    ->get(['id', 'invoice_no', 'invoiced_at', 'total', 'due_amount'])
                    ->map(fn (ServiceInvoice $invoice) => [
                        'id' => $invoice->id,
                        'invoice_no' => $invoice->invoice_no,
                        'invoiced_at' => $invoice->invoiced_at->toIso8601String(),
                        'total' => $invoice->total,
                        'due_amount' => $invoice->due_amount,
                    ]),
            ] : null,
            'saleId' => $request->integer('sale_id') ?: null,
            'serviceInvoiceId' => $request->integer('service_invoice_id') ?: null,
        ]);
    }

    public function store(CustomerPaymentRequest $request, CollectSalePayment $collect): RedirectResponse
    {
        $payment = $collect->handle(
            party: Party::findOrFail($request->integer('party_id')),
            amount: (string) $request->validated('amount'),
            method: PaymentMethod::from($request->validated('method')),
            date: $request->validated('date'),
            sale: $request->filled('sale_id') ? Sale::findOrFail($request->integer('sale_id')) : null,
            referenceNo: $request->validated('reference_no'),
            notes: $request->validated('notes'),
            serviceInvoice: $request->filled('service_invoice_id') ? ServiceInvoice::findOrFail($request->integer('service_invoice_id')) : null,
        );

        return to_route('customer-payments.show', $payment)->with('success', "Payment {$payment->payment_no} received.");
    }

    public function show(Payment $payment): Response
    {
        Gate::authorize('viewAny', Sale::class);
        abort_unless($payment->purpose === PaymentPurpose::SalePayment, 404);

        return Inertia::render('customer-payments/show', [
            'payment' => new PaymentResource($payment->load(['party', 'allocations.allocatable', 'creator:id,name'])),
        ]);
    }
}
