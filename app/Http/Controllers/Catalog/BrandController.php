<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\DeleteCatalogRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BrandRequest;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Brand::class);

        $search = trim((string) $request->query('search', ''));

        $records = Brand::query()
            ->withCount('products')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Brand $record) => [
                'id' => $record->id,
                'name' => $record->name,
                'is_active' => $record->is_active,
                'products_count' => $record->products_count,
            ]);

        return Inertia::render('catalog/brands', [
            'records' => $records,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(BrandRequest $request): RedirectResponse
    {
        $record = Brand::create($request->validated());

        return back()->with('success', "Brand {$record->name} created.");
    }

    public function update(BrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->validated());

        return back()->with('success', "Brand {$brand->name} updated.");
    }

    public function destroy(Brand $brand, DeleteCatalogRecord $delete): RedirectResponse
    {
        Gate::authorize('delete', $brand);

        $delete->handle($brand, ['products']);

        return back()->with('success', "Brand {$brand->name} deleted.");
    }
}
