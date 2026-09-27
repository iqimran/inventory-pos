<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\LowStockQuery;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LowStockController extends Controller
{
    public function index(Request $request, LowStockQuery $lowStock): Response
    {
        Gate::authorize('viewAny', StockMovement::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:191'],
            'category_id' => ['nullable', 'integer'],
            'only_out_of_stock' => ['nullable', 'boolean'],
        ]);

        $products = $lowStock->query([
            'q' => $filters['q'] ?? null,
            'category_id' => $filters['category_id'] ?? null,
            'only_out_of_stock' => (bool) ($filters['only_out_of_stock'] ?? false),
        ])
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'category' => $product->category->name,
                'subcategory' => $product->subcategory?->name,
                'brand' => $product->brand?->name,
                'unit' => $product->unit->short_name,
                'stock' => (int) $product->stock_quantity,
                'reorder_level' => $product->reorder_level,
                'shortfall' => (int) $product->shortfall,
            ]);

        return Inertia::render('inventory/low-stock', [
            'products' => $products,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'category_id' => $filters['category_id'] ?? null,
                'only_out_of_stock' => (bool) ($filters['only_out_of_stock'] ?? false),
            ],
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
