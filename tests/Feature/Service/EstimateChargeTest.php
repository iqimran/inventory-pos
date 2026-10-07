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
 * The job estimate is billed as an editable "Estimated service charge" line, so the draft total
 * and the invoice include it, without ever billing the estimate on top of the real lines.
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

    public function test_the_line_follows_the_estimate_until_edited_by_hand()
    {
        $job = $this->openJob(['estimated_amount' => '1000.00']);

        $this->edit($job, '1300.00');
        $this->assertSame([['Estimated service charge', '1300.00', true]], $this->charges($job));
        $this->assertSame('1300.00', $job->fresh()->service_charge);

        // Estimate given while asking for approval.
        $this->moveTo($job, 'DIAGNOSING');
        $this->moveTo($job, 'WAITING_FOR_APPROVAL', ['diagnosis' => 'Board', 'estimated_amount' => '1400.00'])->assertSessionHasNoErrors();
        $this->assertSame('1400.00', $job->fresh()->service_charge);

        // Edited by hand: an ordinary charge from now on.
        $line = $job->charges()->sole();
        $this->actingAs($this->technician)->put("/service/jobs/{$job->id}/charges/{$line->id}", ['description' => 'Board repair', 'amount' => '900.00'])
            ->assertSessionHasNoErrors();
        $this->edit($job, '2000.00');

        $this->assertSame([['Board repair', '900.00', false]], $this->charges($job));
        $this->assertSame('900.00', $job->fresh()->service_charge);
    }

    public function test_clearing_the_estimate_removes_the_line()
    {
        $job = $this->openJob(['estimated_amount' => '800.00']);

        $this->edit($job, '0');

        $this->assertSame([], $this->charges($job));
        $this->assertSame('0.00', $job->fresh()->service_charge);
    }

    public function test_real_parts_or_charges_replace_the_estimate_line_so_it_is_not_billed_twice()
    {
        $ic = Product::factory()->withStock(5)->create(['retail_price' => '800.00']);

        $withPart = $this->openJob(['estimated_amount' => '1500.00']);
        $this->addPart($withPart, $ic, 1)->assertSessionHasNoErrors();
        $this->assertSame([], $this->charges($withPart));
        $this->assertSame('0.00', $withPart->fresh()->service_charge);

        $withCharge = $this->openJob(['estimated_amount' => '1500.00']);
        $this->addCharge($withCharge, '500.00')->assertSessionHasNoErrors();
        $this->assertSame([['Repair / labour', '500.00', false]], $this->charges($withCharge));
        $this->assertSame('500.00', $withCharge->fresh()->service_charge);

        // A later estimate change does not bring the placeholder back next to real lines.
        $this->edit($withCharge, '1800.00');
        $this->assertSame('500.00', $withCharge->fresh()->service_charge);
    }

    public function test_invoiced_jobs_are_not_changed()
    {
        $job = $this->makeReady($this->openJob(['estimated_amount' => '700.00']));
        $this->invoice($job)->assertSessionHasNoErrors();

        $this->assertSame([['Estimated service charge', '700.00', true]], $this->charges($job));
        $this->assertSame('700.00', ServiceInvoice::sole()->total);
    }
}
