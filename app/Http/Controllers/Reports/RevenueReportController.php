<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\RevenueReport;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * T041 product revenue (PRODUCT lines), T042 mobile service revenue (SERVICE lines) and
 * T043 combined revenue (PRODUCT + SERVICE) — all from RevenueReport, so they always reconcile.
 */
class RevenueReportController extends Controller
{
    public function product(ReportRequest $request, RevenueReport $report): Response
    {
        $period = $request->period();
        $showCost = $request->user()->can(Permission::PurchasesView->value);
        $totals = $report->totals($period);

        return Inertia::render('reports/product-revenue', [
            'filters' => $request->filters(),
            'totals' => $showCost ? $totals['product'] : array_diff_key($totals['product'], array_flip(['cost', 'gross_profit'])),
            'periods' => $report->byPeriod($period),
            'products' => $report->byProduct($period)->through(fn (array $row) => $showCost ? $row : array_diff_key($row, ['cost' => true])),
            'showCost' => $showCost,
        ]);
    }

    public function service(ReportRequest $request, RevenueReport $report): Response
    {
        $period = $request->period();

        return Inertia::render('reports/service-revenue', [
            'filters' => $request->filters(),
            'totals' => $report->totals($period)['service'],
            'periods' => $report->byPeriod($period),
            'technicians' => $report->serviceByTechnician($period),
            'lines' => $report->serviceLineDetails($period),
        ]);
    }

    public function combined(ReportRequest $request, RevenueReport $report): Response
    {
        $period = $request->period();
        $totals = $report->totals($period);

        return Inertia::render('reports/revenue', [
            'filters' => $request->filters(),
            'totals' => [
                'product' => $totals['product']['net'],
                'service' => $totals['service']['revenue'],
                'combined' => $totals['combined'],
                'product_breakdown' => array_intersect_key($totals['product'], array_flip(['pos_sales', 'service_parts', 'returns'])),
                'documents' => $totals['documents'],
            ],
            'periods' => $report->byPeriod($period),
        ]);
    }
}
