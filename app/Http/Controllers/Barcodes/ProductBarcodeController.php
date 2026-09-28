<?php

namespace App\Http\Controllers\Barcodes;

use App\Actions\Products\AssignProductBarcode;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProductBarcodeController extends Controller
{
    /**
     * Assign an internal barcode to a product that has none.
     */
    public function store(Request $request, Product $product, AssignProductBarcode $assignBarcode): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $product);

        $product = $assignBarcode->handle($product);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['data' => ['id' => $product->id, 'barcode' => $product->barcode]]);
        }

        return back()->with('success', "Barcode {$product->barcode} assigned to {$product->name}.");
    }
}
