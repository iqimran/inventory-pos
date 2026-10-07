<?php

namespace Tests\Feature\Reports;

use App\Enums\Permission;
use App\Models\User;

class ReportAccessTest extends ReportTestCase
{
    private const REPORTS = ['/reports/sales', '/reports/product-revenue', '/reports/service-revenue', '/reports/revenue', '/reports/stock', '/reports/parties'];

    public function test_reports_need_reports_view_and_the_module_permission()
    {
        $clerk = $this->generalUser();

        foreach (self::REPORTS as $url) {
            $this->actingAs($clerk)->get($url)->assertForbidden();
        }

        // reports.view alone opens no report: each also needs the permission of the data it shows.
        $reportsOnly = User::factory()->create();
        $reportsOnly->givePermissionTo(Permission::ReportsView->value);
        foreach ([...self::REPORTS, '/expenses/report'] as $url) {
            $this->actingAs($reportsOnly)->get($url)->assertForbidden();
        }

        $matrix = [
            '/reports/sales' => ['sales.view'],
            '/reports/product-revenue' => ['sales.view'],
            '/reports/service-revenue' => ['service.view'],
            '/reports/revenue' => ['sales.view', 'service.view'],
            '/reports/stock' => ['inventory.view'],
            '/reports/parties' => ['parties.view'],
        ];

        foreach ($matrix as $url => $modulePermissions) {
            $user = User::factory()->create();
            $user->givePermissionTo('reports.view', ...$modulePermissions);
            $this->actingAs($user)->get($url)->assertOk();

            $withoutReports = User::factory()->create();
            $withoutReports->givePermissionTo(...$modulePermissions);
            $this->actingAs($withoutReports)->get($url)->assertForbidden();
        }

        // The expense report needs expenses.view (reports.view is not enough).
        $expenses = User::factory()->create();
        $expenses->givePermissionTo('expenses.view');
        $this->actingAs($expenses)->get('/expenses/report')->assertOk();
    }

    public function test_date_range_validation()
    {
        $this->get('/reports/sales?from=2026-09-10&to=2026-09-01')->assertSessionHasErrors('to');
        $this->get('/reports/sales?from=15-09-2026')->assertSessionHasErrors('from');
        $this->get('/reports/revenue?group_by=year')->assertSessionHasErrors('group_by');
        $this->get('/reports/revenue?from=2024-01-01&to=2026-09-15')->assertSessionHasErrors('group_by');
        $this->get('/reports/revenue?from=2024-01-01&to=2026-09-15&group_by=month')->assertOk();
        $this->get('/reports/stock?status=bogus')->assertSessionHasErrors('status');
        $this->get('/reports/parties?type=ALIEN')->assertSessionHasErrors('type');
    }
}
