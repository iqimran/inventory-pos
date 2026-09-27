<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Data\StockMovementData;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\StockService;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
    }

    private function move(Product $product, StockMovementType $type, int $quantity): StockMovement
    {
        return $this->stock->record(new StockMovementData($product->id, $type, $quantity));
    }

    public function test_type_determines_the_sign_of_the_quantity()
    {
        $product = Product::factory()->create();

        $in = $this->move($product, StockMovementType::PurchaseIn, 10);
        $out = $this->move($product, StockMovementType::SaleOut, 3);

        $this->assertSame(10, $in->quantity);
        $this->assertSame(-3, $out->quantity);
        $this->assertSame(10, $in->balance_after);
        $this->assertSame(7, $out->balance_after);
    }

    public function test_every_type_moves_stock_in_its_documented_direction()
    {
        $expected = [
            'PURCHASE_IN' => 1, 'SALE_RETURN_IN' => 1, 'ADJUSTMENT_IN' => 1,
            'PURCHASE_RETURN_OUT' => -1, 'SALE_OUT' => -1, 'SERVICE_PART_OUT' => -1, 'ADJUSTMENT_OUT' => -1,
        ];

        foreach (StockMovementType::cases() as $type) {
            $this->assertSame($expected[$type->value], $type->direction(), $type->value);
        }
    }

    public function test_balance_always_equals_the_sum_of_movements()
    {
        $product = Product::factory()->create();
        $sequence = [
            [StockMovementType::PurchaseIn, 20],
            [StockMovementType::SaleOut, 5],
            [StockMovementType::SaleReturnIn, 1],
            [StockMovementType::ServicePartOut, 2],
            [StockMovementType::PurchaseReturnOut, 4],
            [StockMovementType::AdjustmentIn, 3],
            [StockMovementType::AdjustmentOut, 1],
        ];

        foreach ($sequence as [$type, $quantity]) {
            $this->move($product, $type, $quantity);
            $this->assertSame($this->stock->ledgerBalance($product), $this->stock->balance($product));
        }

        $this->assertSame(12, $this->stock->balance($product));
        $this->assertSame(12, StockMovement::where('product_id', $product->id)->latest('id')->value('balance_after'));
    }

    public function test_stock_cannot_go_negative_by_default()
    {
        $product = Product::factory()->create();
        $this->move($product, StockMovementType::PurchaseIn, 2);

        try {
            $this->move($product, StockMovementType::SaleOut, 3);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('2 available, 3 requested', $e->errors()['quantity'][0]);
        }

        $this->assertSame(2, $this->stock->balance($product));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_negative_stock_is_allowed_when_policy_permits()
    {
        config(['inventory.allow_negative_stock' => true]);
        $product = Product::factory()->create();

        $movement = $this->move($product, StockMovementType::SaleOut, 3);

        $this->assertSame(-3, $movement->balance_after);
        $this->assertSame(-3, $this->stock->balance($product));
    }

    public function test_record_many_is_atomic()
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $this->move($a, StockMovementType::PurchaseIn, 5);

        try {
            $this->stock->recordMany([
                new StockMovementData($a->id, StockMovementType::SaleOut, 2),
                new StockMovementData($b->id, StockMovementType::SaleOut, 1), // b has no stock
            ]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(5, $this->stock->balance($a));
        $this->assertSame(0, $this->stock->balance($b));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_record_many_applies_multiple_lines_for_the_same_product_in_order()
    {
        $product = Product::factory()->create();

        $movements = $this->stock->recordMany([
            new StockMovementData($product->id, StockMovementType::PurchaseIn, 5),
            new StockMovementData($product->id, StockMovementType::SaleOut, 4),
            new StockMovementData($product->id, StockMovementType::SaleOut, 1),
        ]);

        $this->assertSame([5, 1, 0], $movements->pluck('balance_after')->all());
        $this->assertSame(0, $this->stock->balance($product));
    }

    public function test_movement_records_audit_and_reference_information()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $product = Product::factory()->create();
        $reference = User::factory()->create(); // any model can be a reference

        $movement = $this->stock->record(new StockMovementData(
            productId: $product->id,
            type: StockMovementType::PurchaseIn,
            quantity: 4,
            unitCost: '125.50',
            reference: $reference,
            notes: 'Test',
            occurredAt: now()->subDay(),
        ));

        $this->assertSame($user->id, $movement->created_by);
        $this->assertSame('125.50', $movement->fresh()->unit_cost);
        $this->assertSame($reference->getMorphClass(), $movement->reference_type);
        $this->assertSame($reference->id, $movement->reference_id);
        $this->assertTrue($movement->occurred_at->isYesterday());
    }

    public function test_quantity_must_be_positive()
    {
        $this->expectException(InvalidArgumentException::class);

        new StockMovementData(1, StockMovementType::PurchaseIn, 0);
    }

    public function test_unknown_product_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->stock->record(new StockMovementData(999, StockMovementType::PurchaseIn, 1));
    }

    public function test_movements_are_immutable()
    {
        $product = Product::factory()->create();
        $movement = $this->move($product, StockMovementType::PurchaseIn, 1);

        try {
            $movement->update(['notes' => 'tampered']);
            $this->fail('Movement update should be blocked');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_balance_row_is_created_if_missing()
    {
        $product = Product::factory()->create();
        $product->stock()->delete();

        $this->move($product, StockMovementType::PurchaseIn, 3);

        $this->assertSame(3, $this->stock->balance($product));
    }
}
