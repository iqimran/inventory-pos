<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AdjustStock;
use App\Domain\Inventory\ProductSearch;
use App\Enums\AdjustmentReason;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', StockMovement::class);

        $adjustments = StockMovement::query()
            ->with(['product:id,name,sku', 'creator:id,name'])
            ->whereIn('type', [StockMovementType::AdjustmentIn, StockMovementType::AdjustmentOut])
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(25);

        return Inertia::render('inventory/adjustments/index', [
            'adjustments' => StockMovementResource::collection($adjustments),
        ]);
    }

    public function create(Request $request, ProductSearch $search): Response
    {
        Gate::authorize('adjust', StockMovement::class);

        $term = trim((string) $request->query('q', ''));
        $selectedId = $request->integer('product_id') ?: null;

        return Inertia::render('inventory/adjustments/create', [
            'reasons' => AdjustmentReason::options(),
            'selected' => $selectedId
                ? ProductResource::make(Product::with(ProductSearch::LIST_RELATIONS)->find($selectedId))
                : null,
            'results' => $term === ''
                ? []
                : ProductResource::collection($search->query(['q' => $term])->limit(10)->get()),
            'q' => $term,
        ]);
    }

    public function store(StockAdjustmentRequest $request, AdjustStock $adjustStock): RedirectResponse
    {
        $movement = $adjustStock->handle(
            productId: $request->integer('product_id'),
            direction: $request->validated('direction'),
            quantity: $request->integer('quantity'),
            reason: AdjustmentReason::from($request->validated('reason')),
            notes: $request->validated('notes'),
        );

        return to_route('products.show', $movement->product_id)
            ->with('success', "Stock adjusted by {$movement->quantity}. New balance: {$movement->balance_after}.");
    }
}
