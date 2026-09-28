<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceJob;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

/**
 * T047 — audit trail for sensitive changes.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->actingAs($this->admin);
    }

    private function last(string $event): AuditLog
    {
        return AuditLog::where('event', $event)->latest('id')->firstOrFail();
    }

    public function test_product_price_changes_record_old_and_new_values()
    {
        $product = Product::factory()->create(['retail_price' => '500.00', 'purchase_price' => '300.00', 'name' => 'Case']);

        $this->put("/products/{$product->id}", [
            'name' => 'Case', 'sku' => $product->sku, 'barcode' => $product->barcode, 'category_id' => $product->category_id,
            'unit_id' => $product->unit_id, 'purchase_price' => '300.00', 'retail_price' => '450.00', 'wholesale_price' => $product->wholesale_price,
            'reorder_level' => $product->reorder_level, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $log = $this->last('product.updated');
        $this->assertSame(['retail_price' => '500.00'], $log->old_values);
        $this->assertSame(['retail_price' => '450.00'], $log->new_values);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertTrue($log->auditable->is($product));
    }

    public function test_saving_without_changes_writes_nothing()
    {
        $product = Product::factory()->create();
        $payload = $product->only(['name', 'sku', 'barcode', 'category_id', 'unit_id', 'purchase_price', 'retail_price', 'wholesale_price', 'reorder_level']) + ['is_active' => true];

        $this->put("/products/{$product->id}", $payload)->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::where('event', 'product.updated')->count());
    }

    public function test_product_and_master_data_deletions()
    {
        $product = Product::factory()->create(['name' => 'Unused']);
        $this->delete("/products/{$product->id}")->assertSessionHasNoErrors();
        $this->assertSame('Unused', $this->last('product.deleted')->description);

        $unit = Unit::factory()->create(['name' => 'Dozen']);
        $this->delete("/catalog/units/{$unit->id}")->assertSessionHasNoErrors();
        $this->assertSame('Dozen', $this->last('unit.deleted')->old_values['name']);

        $party = Party::factory()->customer()->create(['name' => 'Gone']);
        $this->delete("/parties/{$party->id}")->assertSessionHasNoErrors();
        $this->assertSame('Gone', $this->last('party.deleted')->description);
    }

    public function test_stock_adjustment_and_manual_ledger_adjustment()
    {
        $product = Product::factory()->withStock(10)->create();
        $this->post('/inventory/adjustments', [
            'product_id' => $product->id, 'direction' => 'out', 'quantity' => 3, 'reason' => 'DAMAGED', 'notes' => 'Dropped',
        ])->assertSessionHasNoErrors();

        $log = $this->last('stock.adjusted');
        $this->assertSame(['stock' => 10], $log->old_values);
        $this->assertSame(7, $log->new_values['stock']);
        $this->assertSame(-3, $log->new_values['quantity']);
        $this->assertSame('DAMAGED', $log->new_values['reason']);
        $this->assertSame('Dropped', $log->description);

        $party = Party::factory()->customer()->create();
        $this->post("/parties/{$party->id}/ledger-adjustments", ['side' => 'debit', 'amount' => '150.00', 'reason' => 'Correct opening'])->assertSessionHasNoErrors();
        $log = $this->last('ledger.adjusted');
        $this->assertSame(['balance' => '0.00'], $log->old_values);
        $this->assertSame('150.00', $log->new_values['balance']);
        $this->assertSame('Correct opening', $log->description);
    }

    public function test_pos_and_service_price_overrides()
    {
        $product = Product::factory()->withStock(10)->create(['retail_price' => '300.00']);
        $this->post('/sales', [
            'sale_type' => 'RETAIL', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '250.00']],
            'paid_amount' => '250.00', 'payment_method' => 'CASH',
        ])->assertSessionHasNoErrors();

        $log = $this->last('sale.price_overridden');
        $this->assertSame([['product_id' => $product->id, 'quantity' => 1, 'list_price' => '300.00', 'unit_price' => '250.00']], $log->new_values['lines']);

        $customer = Party::factory()->customer()->create();
        $this->post('/service/jobs', ['party_id' => $customer->id, 'device' => ['brand' => 'X', 'model' => 'Y'], 'complaint' => 'x'])->assertSessionHasNoErrors();
        $job = ServiceJob::sole();
        $this->post("/service/jobs/{$job->id}/parts", ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '280.00'])->assertSessionHasNoErrors();

        $log = $this->last('service_part.price_overridden');
        $this->assertSame(['unit_price' => '300.00'], $log->old_values);
        $this->assertSame('280.00', $log->new_values['unit_price']);
        $this->assertTrue($log->auditable->is($job));
    }

    public function test_a_failed_change_leaves_no_audit_entry()
    {
        $product = Product::factory()->withStock(2)->create();

        // Removing more stock than exists is rejected, and the audit entry rolls back with it.
        $this->post('/inventory/adjustments', ['product_id' => $product->id, 'direction' => 'out', 'quantity' => 5, 'reason' => 'DAMAGED'])
            ->assertSessionHasErrors();

        // Only the opening-stock adjustment from the factory is recorded.
        $this->assertSame(1, AuditLog::where('event', 'stock.adjusted')->where('auditable_id', $product->id)->count());
    }

    public function test_user_role_and_permission_changes_never_store_passwords()
    {
        $this->post('/admin/users', [
            'name' => 'Karim', 'email' => 'karim@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
            'role' => 'General User', 'permissions' => ['sales.view'], 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $user = User::where('email', 'karim@example.com')->sole();

        $created = $this->last('user.created');
        $this->assertSame('General User', $created->new_values['role']);
        $this->assertSame(['sales.view'], $created->new_values['permissions']);
        $this->assertArrayNotHasKey('password', $created->new_values);

        $this->put("/admin/users/{$user->id}", [
            'name' => 'Karim', 'email' => 'karim@example.com', 'password' => 'another-pass-2', 'password_confirmation' => 'another-pass-2',
            'role' => 'General User', 'permissions' => ['sales.view', 'reports.view'],
        ])->assertSessionHasNoErrors();

        $updated = $this->last('user.updated');
        $this->assertSame(['sales.view'], $updated->old_values['permissions']);
        $this->assertSame(['reports.view', 'sales.view'], $updated->new_values['permissions']);
        $this->assertTrue($updated->new_values['password_changed']);
        $this->assertStringNotContainsString('another-pass-2', json_encode($updated->toArray()));

        $this->patch("/admin/users/{$user->id}/status", ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame(['is_active' => false], $this->last('user.deactivated')->new_values);

        $this->post('/admin/roles', ['name' => 'Technician', 'permissions' => ['service.view']])->assertSessionHasNoErrors();
        $role = Role::where('name', 'Technician')->sole();
        $this->put("/admin/roles/{$role->id}", ['name' => 'Technician', 'permissions' => ['service.view', 'service.manage']])->assertSessionHasNoErrors();
        $this->assertSame(['service.manage', 'service.view'], $this->last('role.updated')->new_values['permissions']);
        $this->delete("/admin/roles/{$role->id}")->assertSessionHasNoErrors();
        $this->assertSame('Technician', $this->last('role.deleted')->description);
    }

    public function test_organization_settings_changes()
    {
        config(['shop.name' => 'Old name']);

        $this->put('/settings/organization', ['name' => 'IQ Mobile Care', 'address' => 'Dhaka', 'phone' => '0171', 'receipt_footer' => ''])->assertSessionHasNoErrors();

        $log = $this->last('settings.organization_updated');
        $this->assertSame('Old name', $log->old_values['name']);
        $this->assertSame('IQ Mobile Care', $log->new_values['name']);
        $this->assertNull($log->auditable_type);
    }

    public function test_sign_in_failed_sign_in_and_sign_out()
    {
        auth()->logout();
        $user = User::factory()->create(['email' => 'staff@example.com', 'password' => 'right-password']);

        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'wrong']);
        $failed = $this->last('auth.failed');
        $this->assertSame('staff@example.com', $failed->new_values['email']);
        $this->assertStringNotContainsString('wrong', json_encode($failed->toArray()));

        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'right-password'])->assertRedirect();
        $this->assertSame($user->id, $this->last('auth.login')->user_id);

        $this->post('/logout');
        $this->assertSame($user->id, $this->last('auth.logout')->user_id);
    }

    public function test_audit_entries_are_immutable()
    {
        Category::factory()->create();
        $this->put('/settings/organization', ['name' => 'X'])->assertSessionHasNoErrors();
        $log = AuditLog::firstOrFail();

        $this->expectException(LogicException::class);
        $log->update(['description' => 'tampered']);
    }

    public function test_viewer_filters_and_requires_permission()
    {
        Product::factory()->withStock(5)->create(); // stock.adjusted (opening stock)
        $this->put('/settings/organization', ['name' => 'X'])->assertSessionHasNoErrors();

        $this->get('/admin/audit-logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/audit-logs/index')->where('logs.total', 2)->has('events', 2));
        $this->get('/admin/audit-logs?event=settings')
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 1)->where('logs.data.0.event', 'settings.organization_updated'));
        $this->get('/admin/audit-logs?event=stock.adjusted')
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 1));

        $this->actingAs($this->generalUser())->get('/admin/audit-logs')->assertForbidden();

        $auditor = User::factory()->create();
        $auditor->givePermissionTo(Permission::AuditView->value);
        $this->actingAs($auditor)->get('/admin/audit-logs')->assertOk();
    }
}
