<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\DeleteCatalogRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\UnitRequest;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Unit::class);

        $search = trim((string) $request->query('search', ''));

        $records = Unit::query()
            ->withCount('products')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Unit $record) => [
                'id' => $record->id,
                'name' => $record->name,
                'short_name' => $record->short_name,
                'is_active' => $record->is_active,
                'products_count' => $record->products_count,
            ]);

        return Inertia::render('catalog/units', [
            'records' => $records,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(UnitRequest $request): RedirectResponse
    {
        $record = Unit::create($request->validated());

        return back()->with('success', "Unit {$record->name} created.");
    }

    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update($request->validated());

        return back()->with('success', "Unit {$unit->name} updated.");
    }

    public function destroy(Unit $unit, DeleteCatalogRecord $delete): RedirectResponse
    {
        Gate::authorize('delete', $unit);

        $delete->handle($unit, ['products']);

        return back()->with('success', "Unit {$unit->name} deleted.");
    }
}
