<?php

namespace Tests\Feature\Reports;

use App\Enums\Permission;
use App\Models\User;

class ReportAccessTest extends ReportTestCase
{
    private const REPORTS = ['/reports/sales', '/reports/product-revenue', '/reports/service-revenue', '/reports/revenue', '/reports/stock', '/reports/parties'];

    public function test_reports_need_the_reports_permission()
    {
        $clerk = $this->generalUser();

        foreach (self::REPORTS as $url) {
            $this->actingAs($clerk)->get($url)->assertForbidden();
        }

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::ReportsView->value);

        foreach ([...self::REPORTS, '/expenses/report'] as $url) {
            $this->actingAs($viewer)->get($url)->assertOk();
        }
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
