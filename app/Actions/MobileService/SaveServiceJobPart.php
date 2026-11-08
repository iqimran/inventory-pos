<?php

namespace App\Actions\MobileService;

use App\Domain\Audit\AuditTrail;
use App\Domain\MobileService\ServiceJobGuard;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or changes a part on an open, un-invoiced job. This is a draft line: it does NOT move
 * stock. Stock is consumed (SERVICE_PART_OUT) only when the job is invoiced — see CreateServiceInvoice.
 *
 * Parts are priced at the product's retail price; charging a different price needs the
 * price-override permission, as at the POS.
 */
class SaveServiceJobPart
{
    public function __construct(private readonly ServiceJobGuard $guard, private readonly AuditTrail $audit) {}

    /**
     * @param  array{product_id?: int, quantity: int, unit_price?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(ServiceJob $job, array $data, ?ServiceJobItem $item = null, bool $allowPriceOverride = false): ServiceJobItem
    {
        return DB::transaction(function () use ($job, $data, $item, $allowPriceOverride): ServiceJobItem {
            $job = $this->guard->lockForBilling($job);

            if ($item) {
                $listPrice = Money::of($item->list_price);
                $current = Money::of($item->unit_price);
            } else {
                $product = Product::find($data['product_id']);

                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages(['product_id' => 'This product is not available.']);
                }

                if ($job->items()->where('product_id', $product->id)->exists()) {
                    throw ValidationException::withMessages(['product_id' => "{$product->name} is already on this job; change its quantity instead."]);
                }

                $listPrice = Money::of($product->retail_price);
                $current = $listPrice;
            }

            $unitPrice = isset($data['unit_price']) && $data['unit_price'] !== '' ? Money::of($data['unit_price']) : $current;
            $overridden = Money::cmp($unitPrice, $listPrice) !== 0;

            if ($overridden && ! $allowPriceOverride && Money::cmp($unitPrice, $current) !== 0) {
                throw ValidationException::withMessages(['unit_price' => "You are not allowed to change the part price ({$listPrice})."]);
            }

            $attributes = [
                'quantity' => (int) $data['quantity'],
                'unit_price' => $unitPrice,
                'price_overridden' => $overridden,
                'line_total' => Money::mul($unitPrice, (int) $data['quantity']),
            ];

            if ($item) {
                $item->update($attributes);
            } else {
                $item = $job->items()->create([
                    'product_id' => $product->id,
                    'list_price' => $listPrice,
                    ...$attributes,
                ]);
            }

            if ($overridden && Money::cmp($unitPrice, $current) !== 0) {
                $this->audit->record('service_part.price_overridden', $job, ['unit_price' => $current], [
                    'product_id' => $item->product_id, 'list_price' => $listPrice, 'unit_price' => $unitPrice,
                ], $job->job_no);
            }

            return $item;
        }, 3);
    }
}
