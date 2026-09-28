<?php

namespace Tests\Feature\Service;

use App\Domain\MobileService\Imei;
use App\Enums\Permission;
use App\Models\Device;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Service\Concerns\BuildsServiceJobs;
use Tests\TestCase;

/**
 * T025 — customers (parties) and their devices with IMEI / serial numbers.
 */
class CustomerDeviceTest extends TestCase
{
    use BuildsServiceJobs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpService();
    }

    public function test_imei_check_digit_validation()
    {
        $this->assertTrue(Imei::isValid(self::IMEI_A));
        $this->assertTrue(Imei::isValid(self::IMEI_B));
        $this->assertFalse(Imei::isValid('490154203237519')); // wrong check digit
        $this->assertFalse(Imei::isValid('49015420323751'));  // 14 digits
        $this->assertSame(self::IMEI_A, Imei::normalize('49-015420 323751-8'));
        $this->assertNull(Imei::normalize(' - '));
    }

    public function test_service_desk_can_quick_add_a_customer()
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ServiceView->value, Permission::ServiceManage->value);

        $this->actingAs($user)->postJson('/pos/customers', ['name' => 'Karim', 'phone' => '017-2222 2222'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Karim')
            ->assertJsonPath('data.phone', '01722222222');

        $this->assertSame('CUSTOMER', Party::where('phone', '01722222222')->sole()->type->value);

        $this->actingAs($user)->getJson('/pos/customers?q=Karim')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_register_a_device_with_imei_and_serial()
    {
        $this->actingAs($this->technician)->post('/service/devices', [
            'party_id' => $this->customer->id,
            'brand' => ' Xiaomi ',
            'model' => 'Redmi Note 12',
            'imei1' => '3569 3803 5643 809',
            'imei2' => self::IMEI_C,
            'serial_no' => 'sn-abc-1',
            'color' => 'Blue',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $device = Device::latest('id')->first();
        $this->assertSame($this->customer->id, $device->party_id);
        $this->assertSame('Xiaomi', $device->brand);
        $this->assertSame(self::IMEI_B, $device->imei1);
        $this->assertSame(self::IMEI_C, $device->imei2);
        $this->assertSame('SN-ABC-1', $device->serial_no);
        $this->assertSame($this->technician->id, $device->created_by);
    }

    public function test_imei_and_serial_are_optional()
    {
        $this->actingAs($this->technician)->post('/service/devices', [
            'party_id' => $this->customer->id, 'brand' => 'Nokia', 'model' => '105',
        ])->assertSessionHasNoErrors();

        $device = Device::latest('id')->first();
        $this->assertNull($device->imei1);
        $this->assertNull($device->serial_no);
    }

    public function test_device_validation()
    {
        $post = fn (array $data) => $this->actingAs($this->technician)->post('/service/devices', array_merge([
            'party_id' => $this->customer->id, 'brand' => 'Nokia', 'model' => '105',
        ], $data));

        $post(['imei1' => '490154203237519'])->assertSessionHasErrors('imei1');
        $post(['imei1' => '12345'])->assertSessionHasErrors('imei1');
        $post(['imei1' => self::IMEI_B, 'imei2' => self::IMEI_B])->assertSessionHasErrors('imei2');
        $post(['brand' => ''])->assertSessionHasErrors('brand');
        // Only customers can own devices.
        $post(['party_id' => Party::factory()->supplier()->create()->id])->assertSessionHasErrors('party_id');
        $post(['party_id' => Party::factory()->customer()->inactive()->create()->id])->assertSessionHasErrors('party_id');

        $this->assertSame(1, Device::count());
    }

    public function test_the_same_imei_cannot_be_registered_twice_for_one_customer()
    {
        $this->actingAs($this->technician)->post('/service/devices', [
            'party_id' => $this->customer->id, 'brand' => 'Samsung', 'model' => 'A52', 'imei2' => self::IMEI_A,
        ])->assertSessionHasErrors('imei2');

        // A handset sold on second-hand may be registered to its new owner.
        $other = Party::factory()->customer()->create();
        $this->actingAs($this->technician)->post('/service/devices', [
            'party_id' => $other->id, 'brand' => 'Samsung', 'model' => 'A52', 'imei1' => self::IMEI_A,
        ])->assertSessionHasNoErrors();
    }

    public function test_update_device_keeps_its_owner()
    {
        $this->actingAs($this->technician)->put("/service/devices/{$this->device->id}", [
            'brand' => 'Samsung', 'model' => 'Galaxy A52', 'imei1' => self::IMEI_A, 'color' => 'Black',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Galaxy A52', $this->device->fresh()->model);

        $this->actingAs($this->technician)->put("/service/devices/{$this->device->id}", [
            'party_id' => Party::factory()->customer()->create()->id, 'brand' => 'Samsung', 'model' => 'A52',
        ])->assertSessionHasErrors('party_id');

        $this->assertSame($this->customer->id, $this->device->fresh()->party_id);
    }

    public function test_customer_devices_lookup_and_device_search()
    {
        Device::factory()->create(['party_id' => $this->customer->id, 'brand' => 'Apple', 'model' => 'iPhone 11', 'imei1' => self::IMEI_B]);
        Device::factory()->create(); // another customer's

        $this->actingAs($this->technician)->getJson("/service/customers/{$this->customer->id}/devices")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Apple iPhone 11');

        $this->actingAs($this->technician)->get('/service/devices?q='.substr(self::IMEI_B, 3, 9))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('service/devices/index')->has('devices.data', 1)->where('devices.data.0.imei1', self::IMEI_B));

        // Customer phone.
        $this->actingAs($this->technician)->get('/service/devices?q=01711111111')
            ->assertInertia(fn (Assert $page) => $page->has('devices.data', 2));
    }

    public function test_party_with_devices_cannot_be_deleted()
    {
        $this->actingAs($this->admin())->delete("/parties/{$this->customer->id}")->assertSessionHasErrors('record');

        $this->assertNotNull($this->customer->fresh());
    }

    public function test_authorization()
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ServiceView->value);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get('/service/devices')->assertForbidden();
        $this->actingAs($outsider)->getJson("/service/customers/{$this->customer->id}/devices")->assertForbidden();
        $this->actingAs($viewer)->get('/service/devices')->assertOk();
        $this->actingAs($viewer)->post('/service/devices', ['party_id' => $this->customer->id, 'brand' => 'X', 'model' => 'Y'])->assertForbidden();
        $this->actingAs($viewer)->put("/service/devices/{$this->device->id}", ['brand' => 'X', 'model' => 'Y'])->assertForbidden();

        $this->assertSame(1, Device::count());
    }
}
