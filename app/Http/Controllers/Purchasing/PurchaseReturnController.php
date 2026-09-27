<?php

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseReturnRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReturnController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Purchase::class);

        $returns = PurchaseReturn::query()
            ->with(['party:id,name', 'purchase:id,purchase_no', 'creator:id,name'])
            ->latest('return_date')
            ->latest('id')
            ->paginate(20)
            ->through(fn (PurchaseReturn $return) => [
                'id' => $return->id,
                'return_no' => $return->return_no,
                'return_date' => $return->return_date->toDateString(),
                'purchase' => ['id' => $return->purchase->id, 'purchase_no' => $return->purchase->purchase_no],
                'party' => ['id' => $return->party->id, 'name' => $return->party->name],
                'total' => $return->total,
                'refund_amount' => $return->refund_amount,
                'reason' => $return->reason,
                'created_by' => $return->creator?->name,
            ]);

        return Inertia::render('purchases/returns/index', ['returns' => $returns]);
    }

    public function create(Purchase $purchase): Response
    {
        Gate::authorize('return', $purchase);

        $purchase->load(['party', 'items.product:id,name,sku']);

        return Inertia::render('purchases/returns/create', [
            'purchase' => new PurchaseResource($purchase),
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function store(PurchaseReturnRequest $request, Purchase $purchase, CreatePurchaseReturn $createReturn): RedirectResponse
    {
        $return = $createReturn->handle($purchase, $request->returnData());

        return to_route('purchases.show', $purchase)->with('success', "Return {$return->return_no} recorded.");
    }
}
