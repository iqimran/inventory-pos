<?php

namespace Tests\Feature\Service;

use App\Enums\Permission;
use App\Enums\ServiceJobStatus;
use App\Models\Device;
use App\Models\Party;
use App\Models\ServiceJob;
use App\Models\ServiceJobStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Service\Concerns\BuildsServiceJobs;
use Tests\TestCase;

/**
 * T026 — service job intake and details.
 */
class ServiceJobTest extends TestCase
{
    use BuildsServiceJobs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpService();
    }

    public function test_open_a_job_for_an_existing_device()
    {
        $this->actingAs($this->technician)->post('/service/jobs', [
            'party_id' => $this->customer->id,
            'device_id' => $this->device->id,
            'technician_id' => $this->technician->id,
            'complaint' => 'Phone does not charge',
            'estimated_amount' => '1500',
            'promised_at' => now()->addDays(2)->toDateTimeString(),
            'notes' => 'Customer left the back cover',
        ])->assertSessionHasNoErrors();

        $job = ServiceJob::sole();
        $this->assertMatchesRegularExpression('/^JOB-\d{6}-000001$/', $job->job_no);
        $this->assertSame(ServiceJobStatus::Received, $job->status);
        $this->assertSame($this->customer->id, $job->party_id);
        $this->assertSame($this->device->id, $job->device_id);
        $this->assertSame($this->technician->id, $job->technician_id);
        $this->assertSame('Phone does not charge', $job->complaint);
        $this->assertSame('1500.00', $job->estimated_amount);
        $this->assertSame('0.00', $job->service_charge);
        $this->assertNull($job->approved_amount);
        $this->assertNotNull($job->received_at);
        $this->assertNotNull($job->promised_at);
        $this->assertSame($this->technician->id, $job->created_by);

        $log = ServiceJobStatusLog::sole();
        $this->assertNull($log->from_status);
        $this->assertSame(ServiceJobStatus::Received, $log->to_status);
        $this->assertSame($this->technician->id, $log->created_by);
    }

    public function test_redirects_to_the_job_page()
    {
        $this->actingAs($this->technician)->post('/service/jobs', [
            'party_id' => $this->customer->id, 'device_id' => $this->device->id, 'complaint' => 'Cracked screen',
        ])->assertRedirect('/service/jobs/'.ServiceJob::sole()->id);
    }

    public function test_open_a_job_registering_a_new_device()
    {
        $this->actingAs($this->technician)->post('/service/jobs', [
            'party_id' => $this->customer->id,
            'device' => ['brand' => 'Apple', 'model' => 'iPhone 11', 'imei1' => self::IMEI_B, 'serial_no' => 'f2lxyz'],
            'complaint' => 'Face ID not working',
        ])->assertSessionHasNoErrors();

        $job = ServiceJob::sole();
        $device = $job->device;
        $this->assertSame($this->customer->id, $device->party_id);
        $this->assertSame('Apple', $device->brand);
        $this->assertSame(self::IMEI_B, $device->imei1);
        $this->assertSame('F2LXYZ', $device->serial_no);
        $this->assertSame('0.00', $job->estimated_amount);
    }

    public function test_job_numbers_are_sequential()
    {
        $first = $this->openJob();
        $second = $this->openJob();

        $this->assertStringEndsWith('-000001', $first->job_no);
        $this->assertStringEndsWith('-000002', $second->job_no);
    }

    public function test_intake_validation()
    {
        $post = fn (array $data) => $this->actingAs($this->technician)->post('/service/jobs', array_merge([
            'party_id' => $this->customer->id, 'device_id' => $this->device->id, 'complaint' => 'Dead',
        ], $data));

        $post(['complaint' => ''])->assertSessionHasErrors('complaint');
        $post(['estimated_amount' => '-1'])->assertSessionHasErrors('estimated_amount');
        $post(['estimated_amount' => '1.234'])->assertSessionHasErrors('estimated_amount');
        $post(['party_id' => Party::factory()->supplier()->create()->id])->assertSessionHasErrors('party_id');
        // Another customer's device.
        $post(['device_id' => Device::factory()->create()->id])->assertSessionHasErrors('device_id');
        // No device at all.
        $post(['device_id' => null])->assertSessionHasErrors(['device.brand', 'device.model']);
        // New device with a bad IMEI.
        $post(['device_id' => null, 'device' => ['brand' => 'X', 'model' => 'Y', 'imei1' => '111']])->assertSessionHasErrors('device.imei1');

        $this->assertSame(0, ServiceJob::count());
        // Only the setUp device and the other customer's device: no half-registered device from failed intakes.
        $this->assertSame(2, Device::count());
    }

    public function test_technician_must_be_an_active_user_who_can_manage_service()
    {
        $cashier = User::factory()->create();
        $cashier->givePermissionTo(Permission::SalesCreate->value);
        $inactive = $this->generalUser(['is_active' => false]);
        $admin = $this->admin();

        $this->openJobExpectingError(['technician_id' => $cashier->id], 'technician_id');
        $this->openJobExpectingError(['technician_id' => $inactive->id], 'technician_id');

        $this->assertSame($admin->id, $this->openJob(['technician_id' => $admin->id])->technician_id);

        $direct = User::factory()->create();
        $direct->givePermissionTo(Permission::ServiceManage->value);
        $this->assertSame($direct->id, $this->openJob(['technician_id' => $direct->id])->technician_id);
    }

    public function test_update_job_details_and_reassign_technician()
    {
        $job = $this->openJob();
        $other = $this->generalUser();

        $this->actingAs($this->technician)->put("/service/jobs/{$job->id}", [
            'technician_id' => $other->id,
            'complaint' => 'No charging, heats up',
            'diagnosis' => 'Charging IC shorted',
            'estimated_amount' => '1600.00',
            'notes' => 'Urgent',
        ])->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame($other->id, $job->technician_id);
        $this->assertSame('Charging IC shorted', $job->diagnosis);
        $this->assertSame('1600.00', $job->estimated_amount);
        $this->assertSame(ServiceJobStatus::Received, $job->status); // details never change the status

        // Customer and device are fixed.
        $this->assertSame($this->customer->id, $job->party_id);
    }

    public function test_closed_jobs_cannot_be_edited()
    {
        $job = $this->openJob();
        $this->moveTo($job, 'CANCELLED', ['reason' => 'Customer took the phone back'])->assertSessionHasNoErrors();

        $this->actingAs($this->technician)->put("/service/jobs/{$job->id}", ['complaint' => 'Changed'])->assertSessionHasErrors('job');
        $this->assertSame('No charging', $job->fresh()->complaint);
    }

    public function test_jobs_are_never_deleted()
    {
        $job = $this->openJob();

        $this->expectException(LogicException::class);
        $job->delete();
    }

    public function test_authorization()
    {
        $job = $this->openJob();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ServiceView->value);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get('/service/jobs')->assertForbidden();
        $this->actingAs($outsider)->get("/service/jobs/{$job->id}")->assertForbidden();

        $this->actingAs($viewer)->post('/service/jobs', ['party_id' => $this->customer->id, 'device_id' => $this->device->id, 'complaint' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->put("/service/jobs/{$job->id}", ['complaint' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->get('/service/jobs/create')->assertForbidden();

        $this->assertSame(1, ServiceJob::count());
    }

    private function openJobExpectingError(array $overrides, string $field): void
    {
        $this->actingAs($this->technician)->post('/service/jobs', array_merge([
            'party_id' => $this->customer->id, 'device_id' => $this->device->id, 'complaint' => 'Dead',
        ], $overrides))->assertSessionHasErrors($field);
    }
}
