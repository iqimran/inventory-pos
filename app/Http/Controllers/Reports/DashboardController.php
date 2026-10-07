<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\DashboardSummary;
use App\Domain\Reporting\ReportAccess;
use App\Domain\Reporting\ReportPeriod;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard: the KPI cards the user is allowed to see (ReportAccess), otherwise a welcome page.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardSummary $summary): Response
    {
        $visible = ReportAccess::dashboard($request->user());

        // Nothing the user may see: the welcome page.
        if (! in_array(true, $visible, true)) {
            return Inertia::render('dashboard', ['summary' => null, 'filters' => null]);
        }

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // Default: today.
        $today = CarbonImmutable::now(ReportPeriod::timezone())->toDateString();
        $from = $filters['from'] ?? $today;
        $to = $filters['to'] ?? $today;
        // The trend is daily for up to two months, monthly beyond.
        $groupBy = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 62 ? 'month' : 'day';

        return Inertia::render('dashboard', [
            'summary' => $summary->build(ReportPeriod::make($from, $to, $groupBy), $visible),
            'filters' => ['from' => $from, 'to' => $to, 'group_by' => $groupBy],
        ]);
    }
}
