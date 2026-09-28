<?php

namespace App\Http\Controllers\Barcodes;

use App\Domain\Barcode\BarcodeRenderer;
use App\Enums\LabelLayout;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Barcodes\LabelPrintRequest;
use App\Models\Product;
use App\Support\OrganizationProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product barcode labels: a builder (choose products and quantities) and the printable sheet.
 */
class BarcodeLabelController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize(Permission::BarcodesPrint->value);

        $ids = array_filter(array_map('intval', (array) $request->query('products', [])));

        return Inertia::render('barcodes/labels', [
            'layouts' => LabelLayout::options(),
            'preselected' => Product::whereKey($ids)->get()->map(fn (Product $product) => $this->productPayload($product))->values(),
        ]);
    }

    public function print(LabelPrintRequest $request, BarcodeRenderer $barcodes, OrganizationProfile $organization): Response
    {
        $items = collect($request->validated('items'));
        $products = Product::whereKey($items->pluck('product_id'))->get()->keyBy('id');
        $price = $request->validated('price');
        $layout = LabelLayout::from($request->validated('layout'));

        $labels = $items->map(function (array $item) use ($products, $price, $barcodes) {
            /** @var Product $product */
            $product = $products->get($item['product_id']);

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => match ($price) {
                    'retail' => $product->retail_price,
                    'wholesale' => $product->wholesale_price,
                    default => null,
                },
                // One image per product; the template repeats it `quantity` times.
                'image' => $barcodes->dataUri($product->barcode),
                'quantity' => (int) $item['quantity'],
            ];
        })->values();

        return Inertia::render('barcodes/print', [
            'labels' => $labels,
            'layout' => ['value' => $layout->value, ...$layout->geometry()],
            'options' => [
                'show_sku' => $request->boolean('show_sku'),
                'show_shop' => $request->boolean('show_shop'),
            ],
            'shopName' => $organization->details()['name'],
            'total' => $labels->sum('quantity'),
        ]);
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
            'retail_price' => $product->retail_price,
            'wholesale_price' => $product->wholesale_price,
        ];
    }
}
