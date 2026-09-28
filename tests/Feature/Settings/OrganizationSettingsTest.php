<?php

namespace Tests\Feature\Settings;

use App\Enums\Permission;
use App\Models\Device;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrganizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.name' => 'Env Shop', 'shop.address' => 'Env Road', 'shop.phone' => '0100', 'shop.receipt_footer' => 'Env thanks']);
        $this->admin = $this->admin();
    }

    private function save(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put('/settings/organization', array_merge([
            'name' => 'IQ Mobile Care',
            'address' => "House 12, Road 5\nDhanmondi, Dhaka",
            'phone' => '01711-000000, 01811-000000',
            'receipt_footer' => 'Thank you!',
        ], $data));
    }

    public function test_page_shows_the_environment_values_until_saved()
    {
        $this->actingAs($this->admin)->get('/settings/organization')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/organization')
                ->where('organization.name', 'Env Shop')
                ->where('organization.address', 'Env Road')
                ->where('organization.phone', '0100')
                ->where('organization.receipt_footer', 'Env thanks'));
    }

    public function test_admin_updates_organization_details()
    {
        $this->save([])->assertRedirect('/settings/organization')->assertSessionHasNoErrors();

        $this->assertSame('IQ Mobile Care', Setting::find('organization.name')->value);
        $this->assertSame($this->admin->id, Setting::find('organization.name')->updated_by);

        $this->actingAs($this->admin)->get('/settings/organization')
            ->assertInertia(fn (Assert $page) => $page
                ->where('organization.name', 'IQ Mobile Care')
                ->where('organization.address', "House 12, Road 5\nDhanmondi, Dhaka")
                ->where('organization.phone', '01711-000000, 01811-000000')
                ->where('organization.receipt_footer', 'Thank you!'));
    }

    public function test_cleared_optional_fields_stay_blank_instead_of_falling_back()
    {
        $this->save(['address' => '', 'phone' => '  ', 'receipt_footer' => ''])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get('/settings/organization')
            ->assertInertia(fn (Assert $page) => $page
                ->where('organization.address', null)
                ->where('organization.phone', null)
                ->where('organization.receipt_footer', null));
    }

    public function test_validation()
    {
        $this->save(['name' => ''])->assertSessionHasErrors('name');
        $this->save(['name' => str_repeat('x', 151)])->assertSessionHasErrors('name');
        $this->save(['phone' => str_repeat('1', 101)])->assertSessionHasErrors('phone');

        $this->assertSame(0, Setting::count());
    }

    public function test_requires_the_settings_permission()
    {
        $user = $this->generalUser();

        $this->actingAs($user)->get('/settings/organization')->assertForbidden();
        $this->save([], $user)->assertForbidden();
        $this->assertSame(0, Setting::count());

        $user->givePermissionTo(Permission::SettingsManage->value);
        $this->actingAs($user)->get('/settings/organization')->assertOk();
        $this->save([], $user)->assertSessionHasNoErrors();
    }

    public function test_sale_receipt_and_return_slip_print_the_saved_details()
    {
        $this->save([]);
        $product = Product::factory()->withStock(5)->create(['retail_price' => '100.00']);
        $this->actingAs($this->admin)->post('/sales', [
            'sale_type' => 'RETAIL', 'items' => [['product_id' => $product->id, 'quantity' => 2]], 'paid_amount' => '200.00', 'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();
        $sale = Sale::sole();

        $this->actingAs($this->admin)->get("/sales/{$sale->id}/receipt")
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.name', 'IQ Mobile Care')
                ->where('shop.address', "House 12, Road 5\nDhanmondi, Dhaka")
                ->where('shop.phone', '01711-000000, 01811-000000')
                ->where('shop.receipt_footer', 'Thank you!'));

        $this->actingAs($this->admin)->post("/sales/{$sale->id}/returns", [
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1]], 'reason' => 'Faulty',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get('/sale-returns/'.$sale->returns()->sole()->id)
            ->assertInertia(fn (Assert $page) => $page->where('shop.name', 'IQ Mobile Care')->where('shop.phone', '01711-000000, 01811-000000'));
    }

    public function test_service_invoice_prints_the_saved_details()
    {
        $this->save([]);
        $invoice = $this->serviceInvoice();

        $this->actingAs($this->admin)->get("/service/invoices/{$invoice->id}/print")
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.name', 'IQ Mobile Care')
                ->where('shop.address', "House 12, Road 5\nDhanmondi, Dhaka")
                ->where('shop.phone', '01711-000000, 01811-000000'));
    }

    public function test_upload_a_logo_used_for_sidebar_login_tab_icon_and_invoices()
    {
        Storage::fake('local');

        $this->save(['logo' => UploadedFile::fake()->image('logo.png', 256, 256)])->assertSessionHasNoErrors();

        $path = Setting::find('organization.logo')->value;
        $this->assertStringStartsWith('branding/logo-', $path);
        Storage::disk('local')->assertExists($path);

        $logoUrl = route('branding.logo', ['v' => pathinfo($path, PATHINFO_FILENAME)]);

        // Shared with every page (sidebar) …
        $this->actingAs($this->admin)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('name', 'IQ Mobile Care')
                ->where('organization.name', 'IQ Mobile Care')
                ->where('organization.logo_url', $logoUrl));

        // … the settings page and printed documents …
        $this->actingAs($this->admin)->get('/settings/organization')
            ->assertInertia(fn (Assert $page) => $page->where('organization.logo_url', $logoUrl));

        // … and the public logo file itself, long-cached (the URL is versioned per upload).
        auth()->logout();
        $response = $this->get($logoUrl)->assertOk();
        $this->assertStringContainsString('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=31536000', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_login_page_shows_the_organization_name_logo_title_and_tab_icon()
    {
        Storage::fake('local');
        $this->save(['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);
        auth()->logout();

        $response = $this->get('/login')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('name', 'IQ Mobile Care')
            ->whereNot('organization.logo_url', null));

        $response->assertSee('<title inertia>IQ Mobile Care</title>', false);
        $response->assertSee('rel="icon"', false);
        $response->assertSee(e(app(OrganizationProfile::class)->logoUrl()), false);
    }

    public function test_without_a_logo_the_default_mark_is_used()
    {
        Storage::fake('local');

        $this->get('/login')
            ->assertInertia(fn (Assert $page) => $page->where('organization.logo_url', null)->where('name', 'Env Shop'))
            ->assertDontSee('data-organization-logo', false);

        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_replacing_or_removing_the_logo_deletes_the_old_file()
    {
        Storage::fake('local');

        $this->save(['logo' => UploadedFile::fake()->image('first.png', 64, 64)]);
        $first = Setting::find('organization.logo')->value;

        // Saving other fields keeps the logo.
        $this->save(['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame($first, Setting::find('organization.logo')->value);

        $this->save(['logo' => UploadedFile::fake()->image('second.jpg', 64, 64)]);
        $second = Setting::find('organization.logo')->value;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->save(['remove_logo' => true])->assertSessionHasNoErrors();
        $this->assertNull(Setting::find('organization.logo')->value);
        Storage::disk('local')->assertMissing($second);
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_logo_validation()
    {
        Storage::fake('local');

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->save(['logo' => $svg])->assertSessionHasErrors('logo');
        $this->save(['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        $this->save(['logo' => UploadedFile::fake()->image('big.png', 64, 64)->size(3000)])->assertSessionHasErrors('logo');
        $this->save(['logo' => UploadedFile::fake()->image('tiny.png', 16, 16)])->assertSessionHasErrors('logo');

        $this->assertNull(Setting::find('organization.logo'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_printed_documents_receive_the_logo()
    {
        Storage::fake('local');
        $this->save(['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);
        $invoice = $this->serviceInvoice();

        $this->actingAs($this->admin)->get("/service/invoices/{$invoice->id}/print")
            ->assertInertia(fn (Assert $page) => $page->whereNot('shop.logo_url', null));
    }

    private function serviceInvoice(): ServiceInvoice
    {
        $customer = Party::factory()->customer()->create();
        $device = Device::factory()->create(['party_id' => $customer->id]);
        $this->actingAs($this->admin)->post('/service/jobs', ['party_id' => $customer->id, 'device_id' => $device->id, 'complaint' => 'Dead']);
        $job = ServiceJob::sole();
        $this->actingAs($this->admin)->post("/service/jobs/{$job->id}/charges", ['description' => 'Labour', 'amount' => '300']);

        foreach ([['DIAGNOSING'], ['WAITING_FOR_APPROVAL', ['diagnosis' => 'Board']], ['IN_PROGRESS', ['approved_amount' => '300']], ['READY']] as $step) {
            $this->actingAs($this->admin)->post("/service/jobs/{$job->id}/status", ['status' => $step[0], ...($step[1] ?? [])])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->admin)->post("/service/jobs/{$job->id}/invoice", ['paid_amount' => '300', 'payment_method' => 'CASH'])->assertSessionHasNoErrors();

        return ServiceInvoice::sole();
    }
}
