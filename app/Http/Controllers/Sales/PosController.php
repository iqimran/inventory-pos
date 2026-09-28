<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Parties\SaveParty;
use App\Domain\Inventory\ProductSearch;
use App\Domain\Sales\CustomerDirectory;
use App\Enums\PartyType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\SaleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\QuickCustomerRequest;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The POS counter screen plus lightweight JSON lookups it calls while scanning.
 */
class PosController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('create', Sale::class);

        return Inertia::render('pos/index', [
            'mode' => $request->query('mode') === SaleType::Wholesale->value ? SaleType::Wholesale->value : SaleType::Retail->value,
            'methods' => PaymentMethod::options(),
            'canOverridePrice' => $request->user()->can('overridePrice', Sale::class),
        ]);
    }

    public function products(Request $request, ProductSearch $search): JsonResponse
    {
        // Used by the POS and by service jobs (parts).
        abort_unless($request->user()->can('create', Sale::class) || $request->user()->can(Permission::ServiceManage->value), 403);

        $term = trim((string) $request->query('q', ''));

        $products = $term === ''
            ? collect()
            : $search->query(['q' => $term, 'status' => 'active'])->limit(12)->get();

        return response()->json(['data' => $products->map(fn (Product $product) => $this->productPayload($product))->values()]);
    }

    public function lookup(string $code, ProductSearch $search): JsonResponse
    {
        Gate::authorize('create', Sale::class);

        $product = $search->findByCode($code);

        if (! $product) {
            return response()->json(['message' => "No active product has barcode or SKU “{$code}”."], 404);
        }

        return response()->json(['data' => $this->productPayload($product)]);
    }

    public function customers(Request $request, CustomerDirectory $directory): JsonResponse
    {
        // Used by the POS, due collection and service job intake.
        abort_unless($request->user()->can('create', Sale::class) || $request->user()->can('collect', Sale::class)
            || $request->user()->can(Permission::ServiceManage->value), 403);

        $customers = $directory->search((string) $request->query('q', ''));

        return response()->json(['data' => $customers->map(fn (Party $party) => $this->customerPayload($party))->values()]);
    }

    public function storeCustomer(QuickCustomerRequest $request, SaveParty $saveParty): JsonResponse
    {
        $party = $saveParty->handle(null, [
            ...$request->validated(),
            'type' => PartyType::Customer,
            'is_active' => true,
        ]);

        return response()->json(['data' => $this->customerPayload($party->fresh())], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit' => $product->unit?->short_name,
            'retail_price' => $product->retail_price,
            'wholesale_price' => $product->wholesale_price,
            'stock' => $product->stockQuantity(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(Party $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'phone' => $party->phone,
            'balance' => $party->balance,
        ];
    }
}
