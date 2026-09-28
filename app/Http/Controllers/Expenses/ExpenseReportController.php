<?php

namespace App\Http\Controllers\Expenses;

use App\Domain\Expense\ExpenseReport;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseReportController extends Controller
{
    public function index(Request $request, ExpenseReport $report): Response
    {
        Gate::authorize('viewAny', Expense::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'expense_type_id' => ['nullable', 'integer'],
            'group_by' => ['nullable', 'in:day,month'],
        ]);

        // Default: the current month to date.
        $from = CarbonImmutable::parse($filters['from'] ?? now()->startOfMonth());
        $to = CarbonImmutable::parse($filters['to'] ?? now());
        $typeId = isset($filters['expense_type_id']) ? (int) $filters['expense_type_id'] : null;

        return Inertia::render('expenses/report', [
            'report' => $report->build($from, $to, $typeId, $filters['group_by'] ?? 'day'),
            'types' => ExpenseType::orderBy('name')->get(['id', 'name']),
            'filters' => ['expense_type_id' => $typeId ?? ''],
        ]);
    }
}
