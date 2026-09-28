<?php

namespace App\Http\Controllers\Expenses;

use App\Actions\Catalog\DeleteCatalogRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExpenseTypeRequest;
use App\Models\ExpenseType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseTypeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ExpenseType::class);

        $search = trim((string) $request->query('search', ''));

        $records = ExpenseType::query()
            ->withCount('expenses')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ExpenseType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'is_active' => $type->is_active,
                'expenses_count' => $type->expenses_count,
            ]);

        return Inertia::render('expenses/types', ['records' => $records, 'filters' => ['search' => $search]]);
    }

    public function store(ExpenseTypeRequest $request): RedirectResponse
    {
        $type = ExpenseType::create($request->validated());

        return back()->with('success', "Expense type {$type->name} created.");
    }

    public function update(ExpenseTypeRequest $request, ExpenseType $expenseType): RedirectResponse
    {
        $expenseType->update($request->validated());

        return back()->with('success', "Expense type {$expenseType->name} updated.");
    }

    /**
     * Only unused types can be deleted; types with expenses must be deactivated to keep history intact.
     */
    public function destroy(ExpenseType $expenseType, DeleteCatalogRecord $delete): RedirectResponse
    {
        Gate::authorize('delete', $expenseType);

        $delete->handle($expenseType, ['expenses']);

        return back()->with('success', "Expense type {$expenseType->name} deleted.");
    }
}
