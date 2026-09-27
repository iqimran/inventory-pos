<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockMovementResource;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', StockMovement::class);

        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(StockMovementType::class)],
            'product_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $movements = StockMovement::query()
            ->with(['product:id,name,sku', 'creator:id,name'])
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('occurred_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('occurred_at', '<', now()->parse($to)->addDay()->startOfDay()))
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('inventory/movements', [
            'movements' => StockMovementResource::collection($movements),
            'filters' => [
                'type' => $filters['type'] ?? '',
                'product_id' => $filters['product_id'] ?? null,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'types' => StockMovementType::options(),
        ]);
    }
}
