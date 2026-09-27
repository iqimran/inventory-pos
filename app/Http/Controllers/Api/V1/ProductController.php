<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\ProductSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductSearchRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    /**
     * Search products (active only unless `status` is given).
     */
    public function index(ProductSearchRequest $request, ProductSearch $search): AnonymousResourceCollection
    {
        return ProductResource::collection(
            $search->paginate($request->filters(defaultStatus: 'active'), $request->integer('per_page', 20))
        );
    }

    /**
     * Exact lookup by barcode or SKU, e.g. from a barcode scanner.
     */
    public function lookup(string $code, ProductSearch $search): ProductResource
    {
        Gate::authorize('viewAny', Product::class);

        $product = $search->findByCode($code);

        abort_if($product === null, 404, 'No active product matches this barcode or SKU.');

        return new ProductResource($product);
    }
}
