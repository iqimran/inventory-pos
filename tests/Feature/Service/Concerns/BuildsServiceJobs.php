<?php

namespace Tests\Feature\Service\Concerns;

use App\Domain\Inventory\StockService;
use App\Models\Device;
use App\Models\Party;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Drives service jobs through the real HTTP endpoints so tests exercise validation,
 * authorization and the actions together.
 */
trait BuildsServiceJobs
{
    protected const IMEI_A = '490154203237518';

    protected const IMEI_B = '356938035643809';

    protected const IMEI_C = '861536030196001';

    protected User $technician;

    protected Party $customer;

    protected Device $device;

    protected function setUpService(): void
    {
        $this->technician = $this->generalUser(); // General User holds service.view + service.manage
        $this->customer = Party::factory()->customer()->create(['name' => 'Rahim', 'phone' => '01711111111']);
        $this->device = Device::factory()->create(['party_id' => $this->customer->id, 'brand' => 'Samsung', 'model' => 'A52', 'imei1' => self::IMEI_A]);
    }

    protected function openJob(array $overrides = [], ?User $as = null): ServiceJob
    {
        $this->actingAs($as ?? $this->technician)->post('/service/jobs', array_merge([
            'party_id' => $this->customer->id,
            'device_id' => $this->device->id,
            'complaint' => 'No charging',
            // No estimate by default: an estimate is billed as its own line (see EstimateChargeTest).
            'estimated_amount' => '0',
        ], $overrides))->assertSessionHasNoErrors();

        return ServiceJob::latest('id')->firstOrFail();
    }

    protected function moveTo(ServiceJob $job, string $status, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->technician)->post("/service/jobs/{$job->id}/status", ['status' => $status, ...$data]);
    }

    /**
     * RECEIVED → DIAGNOSING → WAITING_FOR_APPROVAL → IN_PROGRESS → READY.
     */
    protected function makeReady(ServiceJob $job): ServiceJob
    {
        $this->moveTo($job, 'DIAGNOSING')->assertSessionHasNoErrors();
        $this->moveTo($job, 'WAITING_FOR_APPROVAL', ['diagnosis' => 'Charging IC and connector faulty'])->assertSessionHasNoErrors();
        $this->moveTo($job, 'IN_PROGRESS', ['approved_amount' => '1500.00'])->assertSessionHasNoErrors();
        $this->moveTo($job, 'READY')->assertSessionHasNoErrors();

        return $job->fresh();
    }

    protected function addPart(ServiceJob $job, Product $product, int $quantity, array $extra = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->technician)->post("/service/jobs/{$job->id}/parts", ['product_id' => $product->id, 'quantity' => $quantity, ...$extra]);
    }

    protected function addCharge(ServiceJob $job, string $amount, string $description = 'Repair / labour', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->technician)->post("/service/jobs/{$job->id}/charges", ['description' => $description, 'amount' => $amount]);
    }

    protected function invoice(ServiceJob $job, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->technician)->post("/service/jobs/{$job->id}/invoice", array_merge([
            'discount' => '0',
            'paid_amount' => '0',
            'payment_method' => 'CASH',
        ], $data));
    }

    protected function stock(Product $product): int
    {
        return app(StockService::class)->balance($product);
    }
}
