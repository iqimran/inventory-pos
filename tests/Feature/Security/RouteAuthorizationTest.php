<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T048 — authorization regression sweep over EVERY authenticated web route.
 *
 * A signed-in user without any permission must be refused (403) everywhere except the
 * self-service routes below. A new route that forgets its authorization check fails here.
 */
class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Routes every signed-in user may use: own account, dashboard welcome, sign-out. */
    private const SELF_SERVICE = [
        'dashboard', 'settings', 'settings/profile', 'settings/password', 'settings/appearance',
        'verify-email', 'email/verification-notification', 'confirm-password', 'logout',
    ];

    /**
     * @return list<array{0: string, 1: string}> [method, uri template]
     */
    private function businessRoutes(): array
    {
        $routes = [];

        /** @var RouteDefinition $route */
        foreach (Route::getRoutes() as $route) {
            if (! in_array('auth', $route->gatherMiddleware(), true) || in_array($route->uri(), self::SELF_SERVICE, true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, $route->uri()];
            }
        }

        return $routes;
    }

    public function test_every_business_route_refuses_a_user_without_permissions()
    {
        $this->seed(UnitSeeder::class);
        $fixtures = RouteFixtures::create();
        $nobody = User::factory()->create();
        $leaks = [];
        $routes = $this->businessRoutes();

        foreach ($routes as [$method, $uri]) {
            $url = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($fixtures[$m[1]] ?? 1), $uri);
            $status = $this->actingAs($nobody)->call($method, $url)->getStatusCode();

            if ($status !== 403) {
                $leaks[] = "{$method} {$uri} → {$status}";
            }
        }

        $this->assertGreaterThan(100, count($routes), 'Route discovery found too few routes.');
        $this->assertSame([], $leaks, 'Routes reachable without permission:');
    }

    public function test_guests_are_redirected_to_login_everywhere()
    {
        foreach ($this->businessRoutes() as [$method, $uri]) {
            if ($method !== 'GET') {
                continue;
            }

            $url = '/'.preg_replace('/\{(\w+)\??\}/', '1', $uri);
            $this->call($method, $url)->assertRedirect('/login');
        }
    }

    public function test_general_user_is_limited_to_its_granted_permissions()
    {
        $this->seed(UnitSeeder::class);
        $fixtures = RouteFixtures::create();
        $clerk = $this->generalUser();

        // Granted by default: sales, POS, collections, service, viewing products/stock/parties.
        foreach (['/pos', '/sales', '/service/jobs', '/products', '/inventory/movements', '/parties', '/customer-payments/create'] as $url) {
            $this->actingAs($clerk)->get($url)->assertOk();
        }

        // Restricted: stock and financial adjustments, purchasing, expenses, reports, admin, settings, audit.
        $restricted = [
            ['GET', '/inventory/adjustments/create'], ['POST', '/inventory/adjustments'],
            ['POST', "/parties/{$fixtures['party']}/ledger-adjustments"],
            ['GET', '/purchases/create'], ['POST', '/supplier-advances'],
            ['GET', '/expenses/create'], ['POST', "/expenses/{$fixtures['expense']}/void"],
            ['GET', '/reports/revenue'], ['GET', '/admin/users'], ['GET', '/admin/roles'],
            ['GET', '/settings/organization'], ['GET', '/admin/audit-logs'], ['GET', '/barcodes/labels'],
            ['POST', "/products/{$fixtures['product']}/barcode"], ['POST', '/products'],
        ];

        foreach ($restricted as [$method, $url]) {
            $this->assertSame(403, $this->actingAs($clerk)->call($method, $url)->getStatusCode(), "{$method} {$url}");
        }

        // A price override needs its own permission even at the POS.
        $this->actingAs($clerk)->post('/sales', [
            'sale_type' => 'RETAIL', 'items' => [['product_id' => $fixtures['product'], 'quantity' => 1, 'unit_price' => '1.00']],
            'paid_amount' => '1.00', 'payment_method' => 'CASH',
        ])->assertSessionHasErrors('items.0.unit_price');
    }

    public function test_admin_has_full_access()
    {
        $this->seed(UnitSeeder::class);
        $fixtures = RouteFixtures::create();
        $admin = $this->admin();

        foreach ($this->businessRoutes() as [$method, $uri]) {
            // Signed links (email verification) reject forged URLs for everyone, by design.
            if ($method !== 'GET' || in_array('signed', Route::getRoutes()->match(Request::create('/'.preg_replace('/\{(\w+)\??\}/', '1', $uri)))->gatherMiddleware(), true)) {
                continue;
            }

            $url = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($fixtures[$m[1]] ?? 1), $uri);
            $this->assertNotSame(403, $this->actingAs($admin)->get($url)->getStatusCode(), "GET {$uri}");
        }
    }

    public function test_deactivated_users_are_signed_out()
    {
        $user = $this->generalUser(['is_active' => false]);

        $this->actingAs($user)->get('/sales')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_permission_catalogue_is_seeded()
    {
        $this->assertEqualsCanonicalizing(Permission::names(), \Spatie\Permission\Models\Permission::pluck('name')->all());
    }
}
