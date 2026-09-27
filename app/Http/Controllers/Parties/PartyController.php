<?php

namespace App\Http\Controllers\Parties;

use App\Actions\Catalog\DeleteCatalogRecord;
use App\Actions\Parties\SaveParty;
use App\Domain\PartyLedger\PartyStatement;
use App\Domain\PartyLedger\PaymentAllocator;
use App\Enums\OpeningBalanceType;
use App\Enums\PartyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\PartyRequest;
use App\Http\Resources\PartyResource;
use App\Models\Party;
use App\Models\Purchase;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PartyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Party::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', Rule::enum(PartyType::class)],
            'balance' => ['nullable', Rule::in(['payable', 'receivable', 'settled'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $parties = Party::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->when($filters['type'] ?? null, fn ($q, $type) => $type === PartyType::Both->value
                ? $q->where('type', $type)
                : $q->whereIn('type', [$type, PartyType::Both->value]))
            ->when($filters['balance'] ?? null, fn ($q, $balance) => match ($balance) {
                'payable' => $q->where('balance', '<', 0),
                'receivable' => $q->where('balance', '>', 0),
                'settled' => $q->where('balance', 0),
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('parties/index', [
            'parties' => PartyResource::collection($parties),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',
                'balance' => $filters['balance'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'types' => PartyType::options(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Party::class);

        return Inertia::render('parties/create', $this->formOptions());
    }

    public function store(PartyRequest $request, SaveParty $saveParty): RedirectResponse
    {
        $party = $saveParty->handle(null, $request->validated());

        return to_route('parties.show', $party)->with('success', "{$party->name} created.");
    }

    public function show(Request $request, Party $party, PartyStatement $statement, PaymentAllocator $allocator): Response
    {
        Gate::authorize('view', $party);

        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $to = CarbonImmutable::parse($range['to'] ?? today());
        $from = CarbonImmutable::parse($range['from'] ?? $to->subMonthsNoOverflow(3)->startOfMonth());

        $duePurchases = Purchase::query()->where('party_id', $party->id)->where('due_amount', '>', 0);

        return Inertia::render('parties/show', [
            'party' => new PartyResource($party),
            'statement' => $statement->build($party, $from, $to),
            'summary' => [
                'balance' => $party->balance,
                'available_advance' => $allocator->availableAdvance($party),
                'due_purchases_count' => (clone $duePurchases)->count(),
                'due_purchases_amount' => Money::of((string) (clone $duePurchases)->sum('due_amount')),
            ],
        ]);
    }

    public function edit(Party $party): Response
    {
        Gate::authorize('update', $party);

        return Inertia::render('parties/edit', ['party' => new PartyResource($party), ...$this->formOptions()]);
    }

    public function update(PartyRequest $request, Party $party, SaveParty $saveParty): RedirectResponse
    {
        $saveParty->handle($party, $request->validated());

        return to_route('parties.show', $party)->with('success', "{$party->name} updated.");
    }

    public function destroy(Party $party, DeleteCatalogRecord $delete): RedirectResponse
    {
        Gate::authorize('delete', $party);

        $delete->handle($party, ['ledgerEntries', 'purchases', 'payments']);

        return to_route('parties.index')->with('success', "{$party->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => PartyType::options(),
            'openingBalanceTypes' => array_map(fn (OpeningBalanceType $type) => ['value' => $type->value, 'label' => $type->label()], OpeningBalanceType::cases()),
        ];
    }
}
