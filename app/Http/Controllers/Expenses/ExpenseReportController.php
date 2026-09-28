<?php

namespace App\Http\Controllers\Expenses;

use App\Domain\Expense\ExpenseReport;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseReportController extends Controller
{
    public function index(Request $request, ExpenseReport $report): Response
    {
        // Expense staff and report viewers (T046).
        abort_unless($request->user()->can('viewAny', Expense::class) || $request->user()->can(Permission::ReportsView->value), 403);

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
            // The expenses behind the totals (voided ones excluded), paginated.
            'expenses' => ExpenseResource::collection(
                Expense::query()
                    ->recorded()
                    ->with('type:id,name')
                    ->whereDate('expense_date', '>=', $from->toDateString())
                    ->whereDate('expense_date', '<=', $to->toDateString())
                    ->when($typeId, fn ($q) => $q->where('expense_type_id', $typeId))
                    ->latest('expense_date')
                    ->latest('id')
                    ->paginate(25)
                    ->withQueryString()
            ),
            'types' => ExpenseType::orderBy('name')->get(['id', 'name']),
            'filters' => ['expense_type_id' => $typeId ?? ''],
        ]);
    }
}
