<?php

namespace App\Domain\Reporting;

use App\Domain\Expense\ExpenseReport;
use App\Domain\Inventory\LowStockQuery;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard KPIs for a period, built from the same report classes as the detailed reports, so the
 * numbers always agree.
 */
class DashboardSummary
{
    public function __construct(
        private readonly RevenueReport $revenue,
        private readonly PartyLedgerReport $parties,
        private readonly ExpenseReport $expenses,
        private readonly LowStockQuery $lowStock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportPeriod $period, bool $withCost): array
    {
        $revenue = $this->revenue->totals($period);
        [$from, $to] = $period->dateBounds();

        // whereDate: DATE values carry a time part on some drivers (SQLite), which whereBetween would miss.
        $purchases = DB::table('purchases')->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $to)
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(total), 0) as total')->first();
        $purchaseReturns = DB::table('purchase_returns')->whereDate('return_date', '>=', $from)->whereDate('return_date', '<=', $to)->sum('total');
        $expenses = $this->expenses->build($period->from, $period->to, null, 'day');

        return [
            'product_sales' => [
                'amount' => $revenue['product']['net'],
                'quantity' => $revenue['product']['quantity']['net'],
                'gross_profit' => $withCost ? $revenue['product']['gross_profit'] : null,
            ],
            'service_revenue' => $revenue['service']['revenue'],
            'combined_revenue' => $revenue['combined'],
            'documents' => $revenue['documents'],
            'purchases' => [
                'amount' => Money::sub(Money::of((string) $purchases->total), Money::of((string) $purchaseReturns)),
                'documents' => (int) $purchases->documents,
                'returns' => Money::of((string) $purchaseReturns),
            ],
            'expenses' => ['amount' => $expenses['total'], 'entries' => $expenses['count']],
            'outstanding' => $this->parties->outstanding(),
            'low_stock' => [
                'count' => $this->lowStock->count(),
                'products' => $this->lowStock->query()->limit(5)->get()->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => (int) $product->getAttribute('stock_quantity'),
                    'reorder_level' => $product->reorder_level,
                ])->all(),
            ],
            'trend' => $this->revenue->byPeriod($period),
        ];
    }
}
