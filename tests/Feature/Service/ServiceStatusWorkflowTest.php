<?php

namespace Tests\Feature\Service;

use App\Enums\Permission;
use App\Enums\ServiceJobStatus;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Service\Concerns\BuildsServiceJobs;
use Tests\TestCase;

/**
 * T027 — status transition rules and authorization.
 */
class ServiceStatusWorkflowTest extends TestCase
{
    use BuildsServiceJobs, RefreshDatabase;

    private ServiceJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpService();
        $this->job = $this->openJob();
    }

    public function test_transition_map()
    {
        $this->assertSame([ServiceJobStatus::Diagnosing, ServiceJobStatus::Cancelled], ServiceJobStatus::Received->transitions());
        $this->assertSame([ServiceJobStatus::WaitingForApproval, ServiceJobStatus::Cancelled], ServiceJobStatus::Diagnosing->transitions());
        $this->assertSame([ServiceJobStatus::InProgress, ServiceJobStatus::Cancelled], ServiceJobStatus::WaitingForApproval->transitions());
        $this->assertSame([ServiceJobStatus::Ready, ServiceJobStatus::WaitingForApproval, ServiceJobStatus::Cancelled], ServiceJobStatus::InProgress->transitions());
        $this->assertSame([ServiceJobStatus::Delivered, ServiceJobStatus::InProgress, ServiceJobStatus::Cancelled], ServiceJobStatus::Ready->transitions());
        $this->assertSame([], ServiceJobStatus::Delivered->transitions());
        $this->assertSame([], ServiceJobStatus::Cancelled->transitions());
    }

    public function test_full_happy_path_is_logged()
    {
        $this->moveTo($this->job, 'DIAGNOSING', ['notes' => 'Opened the device'])->assertSessionHasNoErrors();
        $this->moveTo($this->job, 'WAITING_FOR_APPROVAL', ['diagnosis' => 'Charging IC faulty', 'estimated_amount' => '1500.00'])->assertSessionHasNoErrors();

        $this->job->refresh();
        $this->assertSame('Charging IC faulty', $this->job->diagnosis);
        $this->assertSame('1500.00', $this->job->estimated_amount);

        $this->moveTo($this->job, 'IN_PROGRESS', ['approved_amount' => '1500.00'])->assertSessionHasNoErrors();
        $this->job->refresh();
        $this->assertSame('1500.00', $this->job->approved_amount);
        $this->assertNotNull($this->job->approved_at);

        $this->moveTo($this->job, 'READY')->assertSessionHasNoErrors();
        $this->addCharge($this->job, '500.00')->assertSessionHasNoErrors();
        $this->invoice($this->job, ['paid_amount' => '500.00'])->assertSessionHasNoErrors();
        $this->moveTo($this->job, 'DELIVERED')->assertSessionHasNoErrors();

        $this->job->refresh();
        $this->assertSame(ServiceJobStatus::Delivered, $this->job->status);
        $this->assertNotNull($this->job->delivered_at);

        $logs = $this->job->statusLogs()->orderBy('id')->get();
        $this->assertSame(
            [null, 'RECEIVED', 'DIAGNOSING', 'WAITING_FOR_APPROVAL', 'IN_PROGRESS', 'READY'],
            $logs->map(fn ($log) => $log->from_status?->value)->all(),
        );
        $this->assertSame(
            ['RECEIVED', 'DIAGNOSING', 'WAITING_FOR_APPROVAL', 'IN_PROGRESS', 'READY', 'DELIVERED'],
            $logs->map(fn ($log) => $log->to_status->value)->all(),
        );
        $this->assertSame('Opened the device', $logs[1]->notes);
        $this->assertTrue($logs->every(fn ($log) => $log->created_by === $this->technician->id));
    }

    public function test_steps_cannot_be_skipped()
    {
        $this->moveTo($this->job, 'IN_PROGRESS', ['approved_amount' => '100'])->assertSessionHasErrors('status');
        $this->moveTo($this->job, 'READY')->assertSessionHasErrors('status');
        $this->moveTo($this->job, 'DELIVERED')->assertSessionHasErrors('status');
        $this->moveTo($this->job, 'RECEIVED')->assertSessionHasErrors('status');
        $this->moveTo($this->job, 'BOGUS')->assertSessionHasErrors('status');

        $this->assertSame(ServiceJobStatus::Received, $this->job->fresh()->status);
        $this->assertSame(1, $this->job->statusLogs()->count());
    }

    public function test_approval_requires_a_diagnosis()
    {
        $this->moveTo($this->job, 'DIAGNOSING');
        $this->moveTo($this->job, 'WAITING_FOR_APPROVAL')->assertSessionHasErrors('diagnosis');
        $this->assertSame(ServiceJobStatus::Diagnosing, $this->job->fresh()->status);

        // A diagnosis saved earlier on the job details is accepted.
        $this->job->update(['diagnosis' => 'Water damage']);
        $this->moveTo($this->job, 'WAITING_FOR_APPROVAL')->assertSessionHasNoErrors();
    }

    public function test_starting_work_requires_the_approved_amount()
    {
        $this->moveTo($this->job, 'DIAGNOSING');
        $this->moveTo($this->job, 'WAITING_FOR_APPROVAL', ['diagnosis' => 'Screen broken']);

        $this->moveTo($this->job, 'IN_PROGRESS')->assertSessionHasErrors('approved_amount');
        $this->moveTo($this->job, 'IN_PROGRESS', ['approved_amount' => '-5'])->assertSessionHasErrors('approved_amount');
        $this->assertSame(ServiceJobStatus::WaitingForApproval, $this->job->fresh()->status);

        $this->moveTo($this->job, 'IN_PROGRESS', ['approved_amount' => '1200.50'])->assertSessionHasNoErrors();
        $this->assertSame('1200.50', $this->job->fresh()->approved_amount);
    }

    public function test_re_approval_and_rework_loops()
    {
        $this->makeReady($this->job);

        // Rework: the device failed the final check.
        $this->moveTo($this->job, 'IN_PROGRESS')->assertSessionHasNoErrors();
        // Extra fault found: ask the customer again.
        $this->moveTo($this->job, 'WAITING_FOR_APPROVAL', ['diagnosis' => 'Also needs a new connector'])->assertSessionHasNoErrors();
        $this->moveTo($this->job, 'IN_PROGRESS', ['approved_amount' => '1800.00'])->assertSessionHasNoErrors();

        $this->assertSame('1800.00', $this->job->fresh()->approved_amount);
        $this->assertSame('Also needs a new connector', $this->job->fresh()->diagnosis);
    }

    public function test_delivery_requires_an_invoice()
    {
        $this->makeReady($this->job);

        $this->moveTo($this->job, 'DELIVERED')->assertSessionHasErrors('status');
        $this->assertSame(ServiceJobStatus::Ready, $this->job->fresh()->status);
        $this->assertNull($this->job->fresh()->delivered_at);
    }

    public function test_cancellation_requires_a_reason_and_is_final()
    {
        $this->moveTo($this->job, 'DIAGNOSING');
        $this->moveTo($this->job, 'CANCELLED')->assertSessionHasErrors('reason');
        $this->moveTo($this->job, 'CANCELLED', ['reason' => '   '])->assertSessionHasErrors('reason');

        $this->moveTo($this->job, 'CANCELLED', ['reason' => 'Customer declined the estimate'])->assertSessionHasNoErrors();

        $job = $this->job->fresh();
        $this->assertSame(ServiceJobStatus::Cancelled, $job->status);
        $this->assertSame('Customer declined the estimate', $job->cancel_reason);
        $this->assertNotNull($job->cancelled_at);
        $this->assertSame('Customer declined the estimate', $job->statusLogs()->latest('id')->first()->notes);

        foreach (['RECEIVED', 'DIAGNOSING', 'IN_PROGRESS', 'READY', 'DELIVERED'] as $status) {
            $this->moveTo($this->job, $status, ['approved_amount' => '1'])->assertSessionHasErrors('status');
        }
    }

    public function test_cancelling_a_job_with_draft_parts_does_not_touch_stock()
    {
        $part = Product::factory()->withStock(5)->create(['retail_price' => '800.00']);
        $this->addPart($this->job, $part, 2)->assertSessionHasNoErrors();

        $this->moveTo($this->job, 'CANCELLED', ['reason' => 'Abandoned'])->assertSessionHasNoErrors();

        $this->assertSame(5, $this->stock($part));
        $this->assertDatabaseMissing('stock_movements', ['type' => 'SERVICE_PART_OUT']);
    }

    public function test_an_invoiced_job_can_only_be_delivered()
    {
        $this->makeReady($this->job);
        $this->addCharge($this->job, '500.00');
        $this->invoice($this->job)->assertSessionHasNoErrors();

        $this->moveTo($this->job, 'CANCELLED', ['reason' => 'Changed mind'])->assertSessionHasErrors('status');
        $this->moveTo($this->job, 'IN_PROGRESS')->assertSessionHasErrors('status');
        $this->assertSame(ServiceJobStatus::Ready, $this->job->fresh()->status);

        $this->moveTo($this->job, 'DELIVERED')->assertSessionHasNoErrors();
        $this->moveTo($this->job, 'READY')->assertSessionHasErrors('status');
    }

    public function test_only_service_managers_can_change_status()
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ServiceView->value);

        $this->moveTo($this->job, 'DIAGNOSING', as: $viewer)->assertForbidden();
        $this->assertSame(ServiceJobStatus::Received, $this->job->fresh()->status);

        $this->moveTo($this->job, 'DIAGNOSING', as: $this->admin())->assertSessionHasNoErrors();
        $this->assertSame(ServiceJobStatus::Diagnosing, $this->job->fresh()->status);
    }
}
