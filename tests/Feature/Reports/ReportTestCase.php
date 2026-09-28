<?php

namespace Tests\Feature\Reports;

use App\Actions\Inventory\AdjustStock;
use App\Actions\MobileService\ChangeServiceJobStatus;
use App\Actions\MobileService\CreateServiceInvoice;
use App\Actions\MobileService\CreateServiceJob;
use App\Actions\MobileService\SaveServiceJobCharge;
use App\Actions\MobileService\SaveServiceJobPart;
use App\Actions\Sales\CreateSale;
use App\Enums\AdjustmentReason;
use App\Enums\ServiceJobStatus;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for report tests: transactions are recorded through the real actions.
 */
abstract class ReportTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Party $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['reports.timezone' => 'UTC']);
        $this->travelTo('2026-09-15 10:00:00');
        $this->admin = $this->admin();
        $this->actingAs($this->admin);
        $this->customer = Party::factory()->customer()->create(['name' => 'Rahim']);
    }

    protected function product(string $name, string $price, int $stock = 50, int $reorder = 5): Product
    {
        $product = Product::factory()->create(['name' => $name, 'retail_price' => $price, 'purchase_price' => '10.00', 'reorder_level' => $reorder]);

        if ($stock > 0) {
            app(AdjustStock::class)->handle($product->id, 'in', $stock, AdjustmentReason::OpeningStock);
        }

        return $product;
    }

    /**
     * @param  list<array{0: Product, 1: int}>  $lines
     */
    protected function sale(array $lines, string $paid = '0', ?Party $party = null, string $discount = '0'): Sale
    {
        return app(CreateSale::class)->handle([
            'sale_type' => 'RETAIL',
            'party_id' => ($party ?? $this->customer)->id,
            'discount' => $discount,
            'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'paid_amount' => $paid,
            'payment_method' => 'CASH',
        ]);
    }

    /**
     * @param  list<array{0: Product, 1: int}>  $parts
     */
    protected function serviceInvoice(array $parts, string $labour, string $paid = '0'): ServiceInvoice
    {
        $job = app(CreateServiceJob::class)->handle(['party_id' => $this->customer->id, 'device' => ['brand' => 'X', 'model' => 'Y'], 'complaint' => 'Fault']);

        foreach ($parts as [$product, $quantity]) {
            app(SaveServiceJobPart::class)->handle($job, ['product_id' => $product->id, 'quantity' => $quantity]);
        }

        app(SaveServiceJobCharge::class)->handle($job, ['description' => 'Repair', 'amount' => $labour]);

        foreach ([ServiceJobStatus::Diagnosing, ServiceJobStatus::WaitingForApproval, ServiceJobStatus::InProgress, ServiceJobStatus::Ready] as $status) {
            app(ChangeServiceJobStatus::class)->handle($job, $status, ['diagnosis' => 'd', 'approved_amount' => '1']);
        }

        return app(CreateServiceInvoice::class)->handle($job, ['paid_amount' => $paid, 'payment_method' => 'CASH']);
    }
}
