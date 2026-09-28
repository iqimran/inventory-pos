<?php

namespace App\Http\Controllers\Expenses;

use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Expense::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'expense_type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ExpenseStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = fn (): Builder => Expense::query()
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $term = trim($term);
                // Case-insensitive on every driver (MySQL collations are, SQLite's = is not).
                $q->where(fn (Builder $match) => $match->where('expense_no', strtoupper($term))->orWhereRaw('LOWER(reference) = ?', [mb_strtolower($term)]));
            })
            ->when($filters['expense_type_id'] ?? null, fn (Builder $q, $id) => $q->where('expense_type_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('expense_date', '<=', $to));

        $expenses = $query()
            ->with(['type:id,name', 'creator:id,name'])
            ->latest('expense_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('expenses/index', [
            'expenses' => ExpenseResource::collection($expenses),
            // Total of the filtered, non-void expenses (voided ones never count).
            'filteredTotal' => Money::of((string) $query()->recorded()->sum('amount')),
            'types' => ExpenseType::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'expense_type_id' => $filters['expense_type_id'] ?? '',
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Expense::class);

        return Inertia::render('expenses/form', ['expense' => null, ...$this->formOptions()]);
    }

    public function store(ExpenseRequest $request, SaveExpense $saveExpense): RedirectResponse
    {
        $expense = $saveExpense->handle(null, $request->expenseData());

        return to_route('expenses.show', $expense)->with('success', "Expense {$expense->expense_no} recorded.");
    }

    public function show(Expense $expense): Response
    {
        Gate::authorize('view', $expense);

        return Inertia::render('expenses/show', [
            'expense' => new ExpenseResource($expense->load([
                'type:id,name',
                'creator:id,name',
                'voider:id,name',
                'audits' => fn ($query) => $query->with('creator:id,name')->orderBy('id'),
            ])),
            'types' => ExpenseType::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function edit(Expense $expense): Response
    {
        Gate::authorize('update', $expense);
        abort_if($expense->isVoid(), 403);

        return Inertia::render('expenses/form', ['expense' => new ExpenseResource($expense->load('type:id,name')), ...$this->formOptions($expense)]);
    }

    public function update(ExpenseRequest $request, Expense $expense, SaveExpense $saveExpense): RedirectResponse
    {
        $saveExpense->handle($expense, $request->expenseData());

        return to_route('expenses.show', $expense)->with('success', "Expense {$expense->expense_no} updated.");
    }

    public function void(Request $request, Expense $expense, VoidExpense $voidExpense): RedirectResponse
    {
        Gate::authorize('void', $expense);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $voidExpense->handle($expense, $data['reason']);

        return to_route('expenses.show', $expense)->with('success', "Expense {$expense->expense_no} voided.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?Expense $expense = null): array
    {
        return [
            // Active types, plus the edited expense's current type even if since deactivated.
            'types' => ExpenseType::query()
                ->where(fn (Builder $q) => $q->where('is_active', true)->when($expense, fn ($q) => $q->orWhere('id', $expense->expense_type_id)))
                ->orderBy('name')
                ->get(['id', 'name']),
            'methods' => PaymentMethod::options(),
        ];
    }
}
