<?php

namespace Tests\Feature\Reports;

use Inertia\Testing\AssertableInertia as Assert;

/**
 * T040 — daily / monthly sales quantity and amount.
 */
class SalesReportTest extends ReportTestCase
{
    public function test_daily_summary_and_invoice_list_with_quantity_and_amount()
    {
        $case = $this->product('Case', '300.00');
        $cable = $this->product('Cable', '150.00');

        $this->sale([[$case, 2], [$cable, 1]], '750.00');             // 750, qty 3
        $this->travelTo('2026-09-16 12:00:00');
        $this->sale([[$cable, 4]], '200.00', discount: '50.00');       // 550, qty 4, due 350
        $this->travelTo('2026-10-02 09:00:00');
        $this->sale([[$case, 1]], '300.00');                            // outside September

        $this->get('/reports/sales?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/sales')
                ->where('filters.from', '2026-09-01')
                ->where('totals', [
                    'invoices' => 2, 'quantity' => 7, 'subtotal' => '1350.00', 'discount' => '50.00',
                    'total' => '1300.00', 'paid' => '950.00', 'due' => '350.00',
                ])
                ->has('periods', 2)
                ->where('periods.0', ['period' => '2026-09-15', 'invoices' => 1, 'quantity' => 3, 'subtotal' => '750.00', 'discount' => '0.00', 'total' => '750.00', 'paid' => '750.00', 'due' => '0.00'])
                ->where('periods.1.quantity', 4)
                ->where('periods.1.total', '550.00')
                ->where('periods.1.discount', '50.00')
                ->where('invoices.total', 2)
                ->where('invoices.data.0.quantity', 4)                  // newest first
                ->where('invoices.data.0.customer', 'Rahim')
                ->where('invoices.data.0.sale_type', 'Retail')
                ->where('invoices.data.0.due', '350.00')
                ->where('invoices.data.1.quantity', 3));
    }

    public function test_monthly_summary()
    {
        $case = $this->product('Case', '100.00');
        $this->sale([[$case, 1]]);
        $this->travelTo('2026-10-05 10:00:00');
        $this->sale([[$case, 2]]);
        $this->sale([[$case, 3]]);

        $this->get('/reports/sales?from=2026-09-01&to=2026-10-31&group_by=month')
            ->assertInertia(fn (Assert $page) => $page
                ->where('periods.0', ['period' => '2026-09', 'invoices' => 1, 'quantity' => 1, 'subtotal' => '100.00', 'discount' => '0.00', 'total' => '100.00', 'paid' => '0.00', 'due' => '100.00'])
                ->where('periods.1.period', '2026-10')
                ->where('periods.1.invoices', 2)
                ->where('periods.1.quantity', 5)
                ->where('periods.1.total', '500.00'));
    }

    public function test_invoice_list_is_paginated()
    {
        $case = $this->product('Case', '10.00', 100);

        foreach (range(1, 27) as $ignored) {
            $this->sale([[$case, 1]]);
        }

        $this->get('/reports/sales?from=2026-09-15&to=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 25)->where('invoices.total', 27)->where('invoices.last_page', 2)->where('totals.invoices', 27));

        $this->get('/reports/sales?from=2026-09-15&to=2026-09-15&page=2')
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 2));
    }

    public function test_defaults_to_month_to_date()
    {
        $this->get('/reports/sales')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-09-01')->where('filters.to', '2026-09-15')->where('filters.group_by', 'day'));
    }
}
