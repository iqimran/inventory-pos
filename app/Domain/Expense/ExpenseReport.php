<?php

namespace App\Domain\Expense;

use App\Models\Expense;
use App\Models\ExpenseType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Expense totals for a date range, by expense type and by day or month. Voided expenses are excluded.
 */
class ExpenseReport
{
    /**
     * @param  'day'|'month'  $groupBy
     * @return array{
     *     from: string, to: string, group_by: string, total: string, count: int,
     *     by_type: list<array{expense_type_id: int, name: string, count: int, total: string, share: string}>,
     *     by_period: list<array{period: string, count: int, total: string}>
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, ?int $typeId = null, string $groupBy = 'day'): array
    {
        $base = fn (): Builder => Expense::query()
            ->recorded()
            // whereDate: correct whether the driver stores DATE values with or without a time part.
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->when($typeId, fn (Builder $q) => $q->where('expense_type_id', $typeId));

        $typeRows = $base()
            ->selectRaw('expense_type_id, COUNT(*) as expense_count, SUM(amount) as total_amount')
            ->groupBy('expense_type_id')
            ->toBase()
            ->get();

        $names = ExpenseType::whereKey($typeRows->pluck('expense_type_id'))->pluck('name', 'id');
        $total = Money::add('0.00', ...$typeRows->map(fn ($row) => Money::of((string) $row->total_amount))->all());

        $byType = $typeRows
            ->map(fn ($row) => [
                'expense_type_id' => (int) $row->expense_type_id,
                'name' => (string) $names->get($row->expense_type_id),
                'count' => (int) $row->expense_count,
                'total' => Money::of((string) $row->total_amount),
                'share' => Money::isPositive($total) ? Money::proportion(Money::of((string) $row->total_amount), 100, $total) : '0.00',
            ])
            ->sortBy([['total', 'desc'], ['name', 'asc']], SORT_NATURAL)
            ->values()
            ->all();

        // Daily totals from the database; months are rolled up here so the SQL stays portable.
        $dailyRows = $base()
            ->selectRaw('expense_date, COUNT(*) as expense_count, SUM(amount) as total_amount')
            ->groupBy('expense_date')
            ->orderBy('expense_date')
            ->toBase()
            ->get();

        $periods = [];

        foreach ($dailyRows as $row) {
            $date = CarbonImmutable::parse($row->expense_date);
            $key = $groupBy === 'month' ? $date->format('Y-m') : $date->toDateString();
            $periods[$key] ??= ['period' => $key, 'count' => 0, 'total' => '0.00'];
            $periods[$key]['count'] += (int) $row->expense_count;
            $periods[$key]['total'] = Money::add($periods[$key]['total'], Money::of((string) $row->total_amount));
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group_by' => $groupBy,
            'total' => $total,
            'count' => (int) $typeRows->sum('expense_count'),
            'by_type' => $byType,
            'by_period' => array_values($periods),
        ];
    }
}
