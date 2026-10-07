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
     * Only the sections in $visible (see ReportAccess::DASHBOARD) are computed and returned; a hidden
     * section is never queried, so its data cannot reach the browser.
     *
     * @param  array<string, bool>  $visible
     * @return array<string, mixed>
     */
    public function build(ReportPeriod $period, array $visible): array
    {
        $summary = [];
        $needsRevenue = ($visible['product_sales'] ?? false) || ($visible['service_revenue'] ?? false)
            || ($visible['combined_revenue'] ?? false) || ($visible['gross_profit'] ?? false);
        $revenue = $needsRevenue ? $this->revenue->totals($period) : null;

        if ($visible['product_sales'] ?? false) {
            $summary['product_sales'] = [
                'amount' => $revenue['product']['net'],
                'quantity' => $revenue['product']['quantity']['net'],
            ];
        }

        if ($visible['gross_profit'] ?? false) {
            $summary['gross_profit'] = $revenue['product']['gross_profit'];
        }

        if ($visible['service_revenue'] ?? false) {
            $summary['service_revenue'] = $revenue['service']['revenue'];
        }

        if ($visible['combined_revenue'] ?? false) {
            $summary['combined_revenue'] = $revenue['combined'];
            $summary['documents'] = $revenue['documents'];
            $summary['trend'] = $this->revenue->byPeriod($period);
        }

        [$from, $to] = $period->dateBounds();

        if ($visible['purchases'] ?? false) {
            // whereDate: DATE values carry a time part on some drivers (SQLite), which whereBetween would miss.
            $purchases = DB::table('purchases')->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $to)
                ->selectRaw('COUNT(*) as documents, COALESCE(SUM(total), 0) as total')->first();
            $purchaseReturns = DB::table('purchase_returns')->whereDate('return_date', '>=', $from)->whereDate('return_date', '<=', $to)->sum('total');
            $summary['purchases'] = [
                'amount' => Money::sub(Money::of((string) $purchases->total), Money::of((string) $purchaseReturns)),
                'documents' => (int) $purchases->documents,
                'returns' => Money::of((string) $purchaseReturns),
            ];
        }

        if ($visible['expenses'] ?? false) {
            $expenses = $this->expenses->build($period->from, $period->to, null, 'day');
            $summary['expenses'] = ['amount' => $expenses['total'], 'entries' => $expenses['count']];
        }

        if ($visible['outstanding'] ?? false) {
            $summary['outstanding'] = $this->parties->outstanding();
        }

        if ($visible['low_stock'] ?? false) {
            $summary['low_stock'] = [
                'count' => $this->lowStock->count(),
                'products' => $this->lowStock->query()->limit(5)->get()->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => (int) $product->getAttribute('stock_quantity'),
                    'reorder_level' => $product->reorder_level,
                ])->all(),
            ];
        }

        return $summary;
    }
}
