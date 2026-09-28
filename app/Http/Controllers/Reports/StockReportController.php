<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\StockReport;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Models\Category;
use Inertia\Inertia;
use Inertia\Response;

/**
 * T044 — current stock (and value) plus a period movement summary, from the stock ledger.
 */
class StockReportController extends Controller
{
    public function __invoke(ReportRequest $request, StockReport $report): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:low,out,in_stock'],
        ]);
        $period = $request->period();
        $showCost = $request->user()->can(Permission::PurchasesView->value);
        $totals = $report->totals($filters);

        return Inertia::render('reports/stock', [
            'filters' => [...$request->filters(), 'q' => $filters['q'] ?? '', 'category_id' => $filters['category_id'] ?? '', 'status' => $filters['status'] ?? ''],
            'totals' => $showCost ? $totals : array_diff_key($totals, ['value' => true]),
            'products' => $report->products($filters)->through(fn (array $row) => $showCost ? $row : array_diff_key($row, ['unit_cost' => true, 'value' => true])),
            'movementTypes' => $report->movementsByType($period),
            'movements' => $report->movementSummary($period, $filters['q'] ?? null),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'showCost' => $showCost,
        ]);
    }
}
