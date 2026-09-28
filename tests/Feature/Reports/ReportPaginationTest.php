<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\ReportPeriod;
use App\Domain\Reporting\RevenueReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T049 — grouped reports paginate correctly while running their aggregate once.
 */
class ReportPaginationTest extends ReportTestCase
{
    public function test_product_revenue_pages_cover_every_product_exactly_once()
    {
        foreach (range(1, 30) as $i) {
            $this->sale([[$this->product("P{$i}", (string) ($i * 10).'.00'), 1]]);
        }

        $period = ReportPeriod::make('2026-09-01', '2026-09-30');
        $this->get('/reports/product-revenue?from=2026-09-01&to=2026-09-30&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.total', 30)
                ->where('products.current_page', 2)
                ->where('products.last_page', 2)
                ->has('products.data', 5)
                ->where('products.data.0.name', 'P5'));              // highest revenue first: P30…P6 on page 1

        $names = collect([1, 2])->flatMap(function (int $page) use ($period) {
            request()->merge(['page' => $page]);

            return collect(app(RevenueReport::class)->byProduct($period)->items())->pluck('name');
        });
        $this->assertCount(30, $names->unique());

        // The aggregate runs once: no separate COUNT(*) over the grouped union.
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RevenueReport::class)->byProduct($period);
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_stock_movement_summary_paginates_on_its_own_page_parameter()
    {
        foreach (range(1, 27) as $i) {
            $this->product("S{$i}", '10.00', stock: 5);
        }

        $this->get('/reports/stock?from=2026-09-01&to=2026-09-30&movements_page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('movements.total', 27)
                ->where('movements.current_page', 2)
                ->has('movements.data', 2)
                ->where('products.current_page', 1));                  // the other list is unaffected
    }

    public function test_lazy_loading_is_prevented_outside_production()
    {
        // Every page test renders multi-row lists; with this guard an N+1 relation throws instead of passing silently.
        $this->assertTrue(Model::preventsLazyLoading());
    }
}
