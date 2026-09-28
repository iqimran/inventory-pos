<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\PartyLedgerReport;
use App\Enums\PartyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * T045 — outstanding receivables/payables and a per-party ledger summary for the period.
 */
class PartyReportController extends Controller
{
    public function __invoke(ReportRequest $request, PartyLedgerReport $report): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(PartyType::class)],
            'side' => ['nullable', 'in:receivable,payable'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return Inertia::render('reports/parties', [
            'filters' => [...$request->filters(), 'type' => $filters['type'] ?? '', 'side' => $filters['side'] ?? '', 'q' => $filters['q'] ?? ''],
            'outstanding' => $report->outstanding($filters['type'] ?? null),
            'parties' => $report->summary($request->period(), $filters),
            'types' => PartyType::options(),
        ]);
    }
}
