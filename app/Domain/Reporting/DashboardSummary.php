<?php

namespace App\Domain\Reporting;

use App\Domain\Expense\ExpenseReport;
use App\Domain\Inventory\LowStockQuery;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
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
     * Only the sections in $visible (see ReportAccess) are computed and returned; a hidden
     * section is never queried, so its data cannot reach the browser.
     *
     * With $createdBy every transaction figure covers only the documents that user recorded.
     *
     * @param  array<string, bool>  $visible
     * @return array<string, mixed>
     */
    public function build(ReportPeriod $period, array $visible, ?int $createdBy = null): array
    {
        $summary = [];
        $revenueReport = $this->revenue->createdBy($createdBy);
        $needsRevenue = ($visible['product_sales'] ?? false) || ($visible['service_revenue'] ?? false)
            || ($visible['combined_revenue'] ?? false) || ($visible['gross_profit'] ?? false);
        $revenue = $needsRevenue ? $revenueReport->totals($period) : null;

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
            $summary['trend'] = $revenueReport->byPeriod($period);
        }

        [$from, $to] = $period->dateBounds();
        $mine = fn (Builder $q) => $q->when($createdBy, fn (Builder $q, int $userId) => $q->where('created_by', $userId));

        if ($visible['purchases'] ?? false) {
            // whereDate: DATE values carry a time part on some drivers (SQLite), which whereBetween would miss.
            $purchases = DB::table('purchases')->tap($mine)->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $to)
                ->selectRaw('COUNT(*) as documents, COALESCE(SUM(total), 0) as total')->first();
            $purchaseReturns = DB::table('purchase_returns')->tap($mine)->whereDate('return_date', '>=', $from)->whereDate('return_date', '<=', $to)->sum('total');
            $summary['purchases'] = [
                'amount' => Money::sub(Money::of((string) $purchases->total), Money::of((string) $purchaseReturns)),
                'documents' => (int) $purchases->documents,
                'returns' => Money::of((string) $purchaseReturns),
            ];
        }

        if ($visible['expenses'] ?? false) {
            $expenses = $this->expenses->build($period->from, $period->to, null, 'day', $createdBy);
            $summary['expenses'] = ['amount' => $expenses['total'], 'entries' => $expenses['count']];
        }

        if ($visible['outstanding'] ?? false) {
            $summary['outstanding'] = $createdBy ? $this->ownOutstanding($createdBy, $visible) : $this->parties->outstanding();
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

    /**
     * What is still owed on the documents one user recorded: customer dues on their sales and service
     * invoices, and the shop's payables on their purchases. Party balances (credit, advances) are
     * shop-wide and stay with Admin.
     *
     * @param  array<string, bool>  $visible
     * @return array<string, string|int>
     */
    private function ownOutstanding(int $userId, array $visible): array
    {
        $due = fn (string $table) => DB::table($table)->where('created_by', $userId)->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(due_amount), 0) as amount, party_id')->groupBy('party_id')->get();

        $customers = $due('sales')->concat($due('service_invoices'));
        // Purchases only for users who work with purchases (OWN_DASHBOARD 'purchases').
        $suppliers = ($visible['purchases'] ?? false) ? $due('purchases') : collect();

        return [
            'customer_receivable' => Money::add('0.00', ...$customers->map(fn ($r) => Money::of((string) $r->amount))->all()),
            'customer_receivable_parties' => $customers->pluck('party_id')->filter()->unique()->count(),
            'customer_credit' => '0.00',
            'customer_credit_parties' => 0,
            'supplier_payable' => Money::add('0.00', ...$suppliers->map(fn ($r) => Money::of((string) $r->amount))->all()),
            'supplier_payable_parties' => $suppliers->pluck('party_id')->filter()->unique()->count(),
            'supplier_advance' => '0.00',
            'supplier_advance_parties' => 0,
        ];
    }
}
