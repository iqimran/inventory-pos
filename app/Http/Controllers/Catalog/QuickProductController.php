<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Products\AssignProductBarcode;
use App\Actions\Products\SaveProduct;
use App\Domain\Inventory\ProductFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Create a product without leaving the current workflow (e.g. the purchase form).
 * Same validation and action as the product screen; JSON responses for the dialog.
 */
class QuickProductController extends Controller
{
    public function options(ProductFormOptions $formOptions): JsonResponse
    {
        Gate::authorize('create', Product::class);

        return response()->json(['data' => $formOptions->for()]);
    }

    public function store(ProductRequest $request, SaveProduct $saveProduct, AssignProductBarcode $assignBarcode): JsonResponse
    {
        $product = $saveProduct->handle(null, $request->productData());

        if ($request->wantsGeneratedBarcode()) {
            $assignBarcode->handle($product);
        }

        $product->refresh()->load(['unit', 'stock']);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }
}
