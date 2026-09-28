<?php

namespace App\Actions\Products;

use App\Domain\Audit\AuditTrail;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteProduct
{
    /**
     * Only products without stock history may be deleted; others are deactivated.
     *
     * @throws ValidationException
     */
    public function handle(Product $product): void
    {
        if ($product->stockMovements()->exists()) {
            throw ValidationException::withMessages([
                'record' => 'This product has stock history and cannot be deleted. Deactivate it instead.',
            ]);
        }

        DB::transaction(function () use ($product): void {
            $audit = app(AuditTrail::class);
            $audit->record('product.deleted', $product, old: $audit->snapshot($product, SaveProduct::AUDITED), description: $product->name);
            $product->stock()->delete();
            $product->delete();
        });
    }
}
