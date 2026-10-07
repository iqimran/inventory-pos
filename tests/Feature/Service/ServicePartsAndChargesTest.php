<?php

namespace Tests\Feature\Service;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceJobCharge;
use App\Models\ServiceJobItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Service\Concerns\BuildsServiceJobs;
use Tests\TestCase;

/**
 * T028 — service parts (draft lines; no stock effect until invoiced).
 * T029 — service / labour charges (SERVICE lines; never stock).
 */
class ServicePartsAndChargesTest extends TestCase
{
    use BuildsServiceJobs, RefreshDatabase;

    private ServiceJob $job;

    private Product $ic;

    private Product $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpService();
        $this->job = $this->openJob();
        $this->ic = Product::factory()->withStock(10)->create(['name' => 'Charging IC', 'retail_price' => '800.00']);
        $this->connector = Product::factory()->withStock(10)->create(['name' => 'Type-C connector', 'retail_price' => '200.00']);
    }

    public function test_adding_parts_to_a_draft_job_does_not_move_stock()
    {
        $movementsBefore = StockMovement::count();

        $this->addPart($this->job, $this->ic, 1)->assertSessionHasNoErrors();
        $this->addPart($this->job, $this->connector, 2)->assertSessionHasNoErrors();

        $parts = $this->job->items()->orderBy('id')->get();
        $this->assertCount(2, $parts);
        $this->assertSame('800.00', $parts[0]->unit_price);
        $this->assertSame('800.00', $parts[0]->list_price);
        $this->assertSame('800.00', $parts[0]->line_total);
        $this->assertSame('400.00', $parts[1]->line_total);
        $this->assertFalse($parts[0]->price_overridden);
        $this->assertNull($parts[0]->consumed_at);
        $this->assertNull($parts[0]->stock_movement_id);
        $this->assertNull($parts[0]->cost_snapshot);
        $this->assertSame($this->technician->id, $parts[0]->created_by);

        // Draft: stock and the stock ledger are untouched.
        $this->assertSame(10, $this->stock($this->ic));
        $this->assertSame(10, $this->stock($this->connector));
        $this->assertSame($movementsBefore, StockMovement::count());
    }

    public function test_parts_can_be_planned_beyond_current_stock()
    {
        // A part may be on order; the stock check happens when the job is invoiced.
        $this->addPart($this->job, $this->ic, 50)->assertSessionHasNoErrors();
        $this->assertSame(50, $this->job->items()->sole()->quantity);
    }

    public function test_change_quantity_and_remove_a_part()
    {
        $this->addPart($this->job, $this->connector, 1);
        $part = $this->job->items()->sole();

        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/parts/{$part->id}", ['quantity' => 3])->assertSessionHasNoErrors();
        $this->assertSame(3, $part->fresh()->quantity);
        $this->assertSame('600.00', $part->fresh()->line_total);

        $this->actingAs($this->technician)->delete("/service/jobs/{$this->job->id}/parts/{$part->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, ServiceJobItem::count());
        $this->assertSame(10, $this->stock($this->connector));
    }

    public function test_part_validation()
    {
        $this->addPart($this->job, $this->ic, 0)->assertSessionHasErrors('quantity');
        $this->addPart($this->job, Product::factory()->inactive()->create(), 1)->assertSessionHasErrors('product_id');
        $this->actingAs($this->technician)->post("/service/jobs/{$this->job->id}/parts", ['product_id' => 999999, 'quantity' => 1])->assertSessionHasErrors('product_id');

        $this->addPart($this->job, $this->ic, 1)->assertSessionHasNoErrors();
        $this->addPart($this->job, $this->ic, 1)->assertSessionHasErrors('product_id'); // already on the job

        $part = $this->job->items()->sole();
        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/parts/{$part->id}", ['product_id' => $this->connector->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(1, ServiceJobItem::count());
    }

    public function test_part_price_override_needs_permission()
    {
        $this->addPart($this->job, $this->ic, 1, ['unit_price' => '700.00'])->assertSessionHasErrors('unit_price');
        $this->assertSame(0, ServiceJobItem::count());

        // Sending the list price is not an override.
        $this->addPart($this->job, $this->ic, 1, ['unit_price' => '800.00'])->assertSessionHasNoErrors();

        $this->technician->givePermissionTo(Permission::SalesPriceOverride->value);
        $this->addPart($this->job, $this->connector, 1, ['unit_price' => '250.00'])->assertSessionHasNoErrors();

        $part = $this->job->items()->where('product_id', $this->connector->id)->sole();
        $this->assertSame('250.00', $part->unit_price);
        $this->assertSame('200.00', $part->list_price);
        $this->assertTrue($part->price_overridden);
    }

    public function test_quantity_change_keeps_an_authorised_override_price()
    {
        $manager = $this->generalUser();
        $manager->givePermissionTo(Permission::SalesPriceOverride->value);
        $this->addPart($this->job, $this->connector, 1, ['unit_price' => '250.00'], $manager);
        $part = $this->job->items()->sole();

        // A technician without the override permission may still change the quantity.
        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/parts/{$part->id}", ['quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame('500.00', $part->fresh()->line_total);

        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/parts/{$part->id}", ['quantity' => 2, 'unit_price' => '100.00'])
            ->assertSessionHasErrors('unit_price');
    }

    public function test_service_charges_are_service_lines_and_never_touch_stock()
    {
        $movementsBefore = StockMovement::count();

        $this->addCharge($this->job, '500.00', 'Repair / labour')->assertSessionHasNoErrors();
        $this->addCharge($this->job, '150.50', 'Software update')->assertSessionHasNoErrors();

        $this->assertSame('650.50', $this->job->fresh()->service_charge);
        $this->assertSame($movementsBefore, StockMovement::count());

        $charge = ServiceJobCharge::where('description', 'Software update')->sole();
        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/charges/{$charge->id}", ['description' => 'Software flash', 'amount' => '200.00'])
            ->assertSessionHasNoErrors();
        $this->assertSame('700.00', $this->job->fresh()->service_charge);

        $this->actingAs($this->technician)->delete("/service/jobs/{$this->job->id}/charges/{$charge->id}")->assertSessionHasNoErrors();
        $this->assertSame('500.00', $this->job->fresh()->service_charge);
        $this->assertSame($movementsBefore, StockMovement::count());
    }

    public function test_charge_validation()
    {
        $this->addCharge($this->job, '0')->assertSessionHasErrors('amount');
        $this->addCharge($this->job, '-10')->assertSessionHasErrors('amount');
        $this->addCharge($this->job, '10.123')->assertSessionHasErrors('amount');
        $this->addCharge($this->job, '10', '')->assertSessionHasErrors('description');

        $this->assertSame(0, ServiceJobCharge::count());
    }

    public function test_lines_belong_to_their_job()
    {
        $otherJob = $this->openJob();
        $this->addPart($otherJob, $this->ic, 1);
        $this->addCharge($otherJob, '100');
        $part = $otherJob->items()->sole();
        $charge = $otherJob->charges()->sole();

        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/parts/{$part->id}", ['quantity' => 5])->assertNotFound();
        $this->actingAs($this->technician)->delete("/service/jobs/{$this->job->id}/parts/{$part->id}")->assertNotFound();
        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/charges/{$charge->id}", ['description' => 'x', 'amount' => '1'])->assertNotFound();
        $this->actingAs($this->technician)->delete("/service/jobs/{$this->job->id}/charges/{$charge->id}")->assertNotFound();

        $this->assertSame(1, $part->fresh()->quantity);
        $this->assertNotNull($charge->fresh());
    }

    public function test_lines_are_locked_once_invoiced()
    {
        $this->addPart($this->job, $this->ic, 1);
        $this->addCharge($this->job, '500.00');
        $this->makeReady($this->job);
        $this->invoice($this->job)->assertSessionHasNoErrors();

        $part = $this->job->items()->sole();
        $charge = $this->job->charges()->sole();

        $this->addPart($this->job, $this->connector, 1)->assertSessionHasErrors('job');
        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/parts/{$part->id}", ['quantity' => 5])->assertSessionHasErrors('job');
        $this->actingAs($this->technician)->delete("/service/jobs/{$this->job->id}/parts/{$part->id}")->assertSessionHasErrors('job');
        $this->addCharge($this->job, '100')->assertSessionHasErrors('job');
        $this->actingAs($this->technician)->put("/service/jobs/{$this->job->id}/charges/{$charge->id}", ['description' => 'x', 'amount' => '1'])->assertSessionHasErrors('job');
        $this->actingAs($this->technician)->delete("/service/jobs/{$this->job->id}/charges/{$charge->id}")->assertSessionHasErrors('job');

        $this->assertSame(1, $part->fresh()->quantity);
        $this->assertSame('500.00', $charge->fresh()->amount);
        $this->assertSame(1, $this->job->items()->count());
    }

    public function test_lines_are_locked_on_cancelled_jobs()
    {
        $this->moveTo($this->job, 'CANCELLED', ['reason' => 'No show']);

        $this->addPart($this->job, $this->ic, 1)->assertSessionHasErrors('job');
        $this->addCharge($this->job, '100')->assertSessionHasErrors('job');
    }

    public function test_viewers_cannot_change_lines()
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ServiceView->value);
        $this->addPart($this->job, $this->ic, 1);
        $part = $this->job->items()->sole();

        $this->addPart($this->job, $this->connector, 1, as: $viewer)->assertForbidden();
        $this->actingAs($viewer)->delete("/service/jobs/{$this->job->id}/parts/{$part->id}")->assertForbidden();
        $this->addCharge($this->job, '100', as: $viewer)->assertForbidden();

        $this->assertSame(1, ServiceJobItem::count());
        $this->assertSame(0, ServiceJobCharge::count());
    }
}
