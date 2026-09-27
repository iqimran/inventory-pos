<?php

namespace Database\Factories;

use App\Actions\Inventory\AdjustStock;
use App\Domain\Inventory\StockService;
use App\Enums\AdjustmentReason;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Products start with a zero balance. Add stock through StockService in tests
 * (e.g. the withStock() state) so the ledger stays consistent.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $purchase = fake()->randomFloat(2, 50, 5000);

        return [
            'category_id' => Category::factory(),
            'subcategory_id' => null,
            'brand_id' => null,
            'unit_id' => Unit::factory(),
            'name' => fake()->unique()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-???')),
            'barcode' => fake()->unique()->ean13(),
            'description' => null,
            'purchase_price' => number_format($purchase, 2, '.', ''),
            'retail_price' => number_format($purchase * 1.3, 2, '.', ''),
            'wholesale_price' => number_format($purchase * 1.15, 2, '.', ''),
            'reorder_level' => 5,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Product $product) => app(StockService::class)->initialise($product));
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Give the product opening stock via an adjustment movement.
     */
    public function withStock(int $quantity): static
    {
        return $this->afterCreating(function (Product $product) use ($quantity) {
            if ($quantity > 0) {
                app(AdjustStock::class)->handle(
                    $product->id, 'in', $quantity, AdjustmentReason::OpeningStock,
                );
            }
        });
    }
}
