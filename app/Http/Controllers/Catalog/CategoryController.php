<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\DeleteCatalogRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Category::class);

        $search = trim((string) $request->query('search', ''));

        $categories = Category::query()
            ->withCount(['subcategories', 'products'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'is_active' => $category->is_active,
                'subcategories_count' => $category->subcategories_count,
                'products_count' => $category->products_count,
            ]);

        return Inertia::render('catalog/categories', [
            'records' => $categories,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $category = Category::create($request->validated());

        return back()->with('success', "Category {$category->name} created.");
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return back()->with('success', "Category {$category->name} updated.");
    }

    public function destroy(Category $category, DeleteCatalogRecord $delete): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $delete->handle($category, ['subcategories', 'products']);

        return back()->with('success', "Category {$category->name} deleted.");
    }
}
