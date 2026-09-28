<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\SalesReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * T040 — daily / monthly POS sales with quantity and amount, and the invoice list.
 */
class SalesReportController extends Controller
{
    public function __invoke(ReportRequest $request, SalesReport $report): Response
    {
        $period = $request->period();

        return Inertia::render('reports/sales', [
            'filters' => $request->filters(),
            'totals' => $report->totals($period),
            'periods' => $report->byPeriod($period),
            'invoices' => $report->invoices($period),
        ]);
    }
}
