<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\DeleteCatalogRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SubcategoryRequest;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SubcategoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Subcategory::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $subcategories = Subcategory::query()
            ->with('category:id,name')
            ->withCount('products')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Subcategory $subcategory) => [
                'id' => $subcategory->id,
                'name' => $subcategory->name,
                'description' => $subcategory->description,
                'category_id' => $subcategory->category_id,
                'category' => $subcategory->category->name,
                'is_active' => $subcategory->is_active,
                'products_count' => $subcategory->products_count,
            ]);

        return Inertia::render('catalog/subcategories', [
            'records' => $subcategories,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'filters' => ['search' => $search, 'category_id' => $filters['category_id'] ?? null],
        ]);
    }

    public function store(SubcategoryRequest $request): RedirectResponse
    {
        $subcategory = Subcategory::create($request->validated());

        return back()->with('success', "Subcategory {$subcategory->name} created.");
    }

    public function update(SubcategoryRequest $request, Subcategory $subcategory): RedirectResponse
    {
        $subcategory->update($request->validated());

        return back()->with('success', "Subcategory {$subcategory->name} updated.");
    }

    public function destroy(Subcategory $subcategory, DeleteCatalogRecord $delete): RedirectResponse
    {
        Gate::authorize('delete', $subcategory);

        $delete->handle($subcategory, ['products']);

        return back()->with('success', "Subcategory {$subcategory->name} deleted.");
    }
}
