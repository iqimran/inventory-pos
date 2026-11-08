<?php

namespace Tests\Feature\Service;

use App\Models\Product;
use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use App\Models\ServiceJobCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Service\Concerns\BuildsServiceJobs;
use Tests\TestCase;

/**
 * The job estimate is billed as an editable "Estimated service charge" line, in addition to the
 * job's parts and other charges, so the draft total and the invoice include it.
 */
class EstimateChargeTest extends TestCase
{
    use BuildsServiceJobs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpService();
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function charges(ServiceJob $job): array
    {
        return $job->charges()->orderBy('id')->get()->map(fn (ServiceJobCharge $c) => [$c->description, $c->amount, $c->is_estimate])->all();
    }

    private function edit(ServiceJob $job, string $estimate): void
    {
        $this->actingAs($this->technician)->put("/service/jobs/{$job->id}", [
            'complaint' => $job->complaint, 'estimated_amount' => $estimate,
        ])->assertSessionHasNoErrors();
    }

    public function test_the_estimate_is_in_the_draft_total_and_the_invoice()
    {
        $job = $this->openJob(['estimated_amount' => '1200.00']);

        $this->assertSame([['Estimated service charge', '1200.00', true]], $this->charges($job));
        $this->actingAs($this->technician)->get("/service/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('job.data.service_charge', '1200.00')
                ->where('job.data.charges.0.is_estimate', true));

        $this->invoice($this->makeReady($job))->assertSessionHasNoErrors();
        $this->assertSame('1200.00', ServiceInvoice::sole()->total);
    }

    public function test_no_estimate_means_no_line()
    {
        $job = $this->openJob(['estimated_amount' => '0']);

        $this->assertSame([], $this->charges($job));
        $this->assertSame('0.00', $job->service_charge);
    }

    public function test_the_line_and_the_estimate_stay_equal()
    {
        $job = $this->openJob(['estimated_amount' => '1000.00']);

        $this->edit($job, '1300.00');
        $this->assertSame([['Estimated service charge', '1300.00', true]], $this->charges($job));
        $this->assertSame('1300.00', $job->fresh()->service_charge);

        // Estimate given while asking for approval.
        $this->moveTo($job, 'DIAGNOSING');
        $this->moveTo($job, 'WAITING_FOR_APPROVAL', ['diagnosis' => 'Board', 'estimated_amount' => '1400.00'])->assertSessionHasNoErrors();
        $this->assertSame('1400.00', $job->fresh()->service_charge);

        // Editing the line changes the estimate.
        $line = $job->charges()->sole();
        $this->actingAs($this->technician)->put("/service/jobs/{$job->id}/charges/{$line->id}", ['description' => 'Board repair (estimate)', 'amount' => '900.00'])
            ->assertSessionHasNoErrors();
        $this->assertSame([['Board repair (estimate)', '900.00', true]], $this->charges($job));
        $this->assertSame('900.00', $job->fresh()->estimated_amount);

        // Deleting the line clears the estimate.
        $this->actingAs($this->technician)->delete("/service/jobs/{$job->id}/charges/{$line->id}")->assertSessionHasNoErrors();
        $this->assertSame([], $this->charges($job));
        $this->assertSame(['0.00', '0.00'], [$job->fresh()->estimated_amount, $job->fresh()->service_charge]);
    }

    public function test_clearing_the_estimate_removes_the_line()
    {
        $job = $this->openJob(['estimated_amount' => '800.00']);

        $this->edit($job, '0');

        $this->assertSame([], $this->charges($job));
        $this->assertSame('0.00', $job->fresh()->service_charge);
    }

    public function test_the_estimate_is_billed_in_addition_to_parts_and_charges()
    {
        // Reported case: estimate 500, parts 120, labour 200 → draft and invoice 820.
        $part = Product::factory()->withStock(5)->create(['retail_price' => '120.00']);
        $job = $this->openJob(['estimated_amount' => '500.00']);
        $this->addPart($job, $part, 1)->assertSessionHasNoErrors();
        $this->addCharge($job, '200.00')->assertSessionHasNoErrors();

        $this->assertSame([['Estimated service charge', '500.00', true], ['Repair / labour', '200.00', false]], $this->charges($job));
        $this->assertSame('700.00', $job->fresh()->service_charge);
        $this->actingAs($this->technician)->get("/service/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page->where('job.data.parts_total', '120.00')->where('job.data.service_charge', '700.00'));

        // Changing the estimate later changes only its own line.
        $this->edit($job, '600.00');
        $this->assertSame('800.00', $job->fresh()->service_charge);

        $this->invoice($this->makeReady($job))->assertSessionHasNoErrors();
        $this->assertSame('920.00', ServiceInvoice::sole()->total);   // 120 + 600 + 200
    }

    public function test_invoiced_jobs_are_not_changed()
    {
        $job = $this->makeReady($this->openJob(['estimated_amount' => '700.00']));
        $this->invoice($job)->assertSessionHasNoErrors();

        $this->assertSame([['Estimated service charge', '700.00', true]], $this->charges($job));
        $this->assertSame('700.00', ServiceInvoice::sole()->total);
    }
}
