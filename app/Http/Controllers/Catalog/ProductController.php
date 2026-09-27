<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\DeleteProduct;
use App\Actions\Products\SaveProduct;
use App\Domain\Inventory\ProductSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Requests\Catalog\ProductSearchRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(ProductSearchRequest $request, ProductSearch $search): Response
    {
        $filters = $request->filters();

        return Inertia::render('products/index', [
            'products' => ProductResource::collection($search->paginate($filters)),
            'filters' => $filters,
            'options' => $this->filterOptions(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Product::class);

        return Inertia::render('products/create', ['options' => $this->formOptions()]);
    }

    public function store(ProductRequest $request, SaveProduct $saveProduct): RedirectResponse
    {
        $product = $saveProduct->handle(null, $request->validated());

        return to_route('products.show', $product)->with('success', "Product {$product->name} created.");
    }

    public function show(Product $product): Response
    {
        Gate::authorize('view', $product);

        $product->load(ProductSearch::LIST_RELATIONS);

        return Inertia::render('products/show', [
            'product' => new ProductResource($product),
            'movements' => fn () => StockMovementResource::collection(
                $product->stockMovements()->with('creator:id,name')->latest('id')->paginate(20)->withQueryString()
            ),
        ]);
    }

    public function edit(Product $product): Response
    {
        Gate::authorize('update', $product);

        return Inertia::render('products/edit', [
            'product' => new ProductResource($product),
            'options' => $this->formOptions($product),
        ]);
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $saveProduct): RedirectResponse
    {
        $saveProduct->handle($product, $request->validated());

        return to_route('products.show', $product)->with('success', "Product {$product->name} updated.");
    }

    public function destroy(Product $product, DeleteProduct $deleteProduct): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $deleteProduct->handle($product);

        return to_route('products.index')->with('success', "Product {$product->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => Subcategory::query()->orderBy('name')->get(['id', 'category_id', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Active master data, plus the product's current (possibly inactive) values.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?Product $product = null): array
    {
        $options = fn (string $model, ?int $current, array $columns) => $model::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('id', $current)))
            ->orderBy('name')
            ->get($columns);

        return [
            'categories' => $options(Category::class, $product?->category_id, ['id', 'name']),
            'subcategories' => $options(Subcategory::class, $product?->subcategory_id, ['id', 'category_id', 'name']),
            'brands' => $options(Brand::class, $product?->brand_id, ['id', 'name']),
            'units' => $options(Unit::class, $product?->unit_id, ['id', 'name', 'short_name']),
        ];
    }
}
