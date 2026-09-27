<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CreateSaleReturn;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SaleReturnRequest;
use App\Http\Resources\SaleResource;
use App\Http\Resources\SaleReturnResource;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SaleReturnController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Sale::class);

        $returns = SaleReturn::query()
            ->with(['sale:id,invoice_no', 'party:id,name,phone', 'creator:id,name'])
            ->latest('returned_at')
            ->latest('id')
            ->paginate(25);

        return Inertia::render('sales/returns/index', ['returns' => SaleReturnResource::collection($returns)]);
    }

    public function create(Sale $sale): Response
    {
        Gate::authorize('return', $sale);

        $sale->load([
            'party',
            'items' => fn ($query) => $query->with('product:id,name,sku')->withSum('returnItems as returned_quantity', 'quantity'),
        ]);

        return Inertia::render('sales/returns/create', [
            'sale' => new SaleResource($sale),
            'methods' => PaymentMethod::options(),
            'customerBalance' => $sale->party?->balance,
        ]);
    }

    public function store(SaleReturnRequest $request, Sale $sale, CreateSaleReturn $createReturn): RedirectResponse
    {
        $return = $createReturn->handle($sale, $request->returnData());

        return to_route('sale-returns.show', $return)->with('success', "Return {$return->return_no} recorded.");
    }

    public function show(SaleReturn $saleReturn): Response
    {
        Gate::authorize('viewAny', Sale::class);

        return Inertia::render('sales/returns/show', [
            'saleReturn' => new SaleReturnResource($saleReturn->load(['sale:id,invoice_no', 'party', 'items.product:id,name,sku', 'creator:id,name'])),
            'shop' => ['name' => config('shop.name'), 'address' => config('shop.address'), 'phone' => config('shop.phone')],
        ]);
    }
}
