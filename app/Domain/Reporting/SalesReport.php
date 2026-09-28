<?php

namespace App\Domain\Reporting;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * POS sales: per-invoice detail and daily/monthly quantity and amount.
 * Paid and due are the invoices' current settlement (later collections and returns included).
 */
class SalesReport
{
    /**
     * @return array{invoices: int, quantity: int, subtotal: string, discount: string, total: string, paid: string, due: string}
     */
    public function totals(ReportPeriod $period): array
    {
        $sales = $this->sales($period)
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(subtotal), 0) as subtotal, COALESCE(SUM(items_discount + discount), 0) as discount,
                COALESCE(SUM(total), 0) as total, COALESCE(SUM(paid_amount), 0) as paid, COALESCE(SUM(due_amount), 0) as due')
            ->first();

        $quantity = $this->items($period)->sum('sale_items.quantity');

        return [
            'invoices' => (int) $sales->invoices,
            'quantity' => (int) $quantity,
            'subtotal' => Money::of((string) $sales->subtotal),
            'discount' => Money::of((string) $sales->discount),
            'total' => Money::of((string) $sales->total),
            'paid' => Money::of((string) $sales->paid),
            'due' => Money::of((string) $sales->due),
        ];
    }

    /**
     * @return list<array{period: string, invoices: int, quantity: int, subtotal: string, discount: string, total: string, paid: string, due: string}>
     */
    public function byPeriod(ReportPeriod $period): array
    {
        $bucket = (string) $period->bucket('sales.sold_at')->getValue(DB::connection()->getQueryGrammar());

        $rows = $this->sales($period)
            ->selectRaw("{$bucket} as bucket, COUNT(*) as invoices, SUM(subtotal) as subtotal, SUM(items_discount + discount) as discount,
                SUM(total) as total, SUM(paid_amount) as paid, SUM(due_amount) as due")
            ->groupBy(DB::raw($bucket))
            ->get()
            ->keyBy('bucket');

        // Quantities come from the lines (a separate aggregate so invoice sums are not multiplied by the join).
        $quantities = $this->items($period)
            ->selectRaw("{$bucket} as bucket, SUM(sale_items.quantity) as quantity")
            ->groupBy(DB::raw($bucket))
            ->pluck('quantity', 'bucket');

        return $rows->sortKeys()->map(fn ($r, $key) => [
            'period' => (string) $key,
            'invoices' => (int) $r->invoices,
            'quantity' => (int) ($quantities[$key] ?? 0),
            'subtotal' => Money::of((string) $r->subtotal),
            'discount' => Money::of((string) $r->discount),
            'total' => Money::of((string) $r->total),
            'paid' => Money::of((string) $r->paid),
            'due' => Money::of((string) $r->due),
        ])->values()->all();
    }

    /**
     * One row per invoice: date/time, invoice, type, customer, quantity, subtotal, discount, total, paid, due.
     */
    public function invoices(ReportPeriod $period, int $perPage = 25): LengthAwarePaginator
    {
        return Sale::query()
            ->with('party:id,name')
            ->withSum('items as quantity', 'quantity')
            ->where('status', SaleStatus::Completed)
            ->whereBetween('sold_at', $period->datetimeBounds())
            ->latest('sold_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Sale $sale) => [
                'id' => $sale->id,
                'sold_at' => $sale->sold_at->toIso8601String(),
                'invoice_no' => $sale->invoice_no,
                'sale_type' => $sale->sale_type->label(),
                'customer' => $sale->party?->name,
                'quantity' => (int) $sale->quantity,
                'subtotal' => $sale->subtotal,
                'discount' => Money::add(Money::of($sale->items_discount), Money::of($sale->discount)),
                'total' => $sale->total,
                'paid' => $sale->paid_amount,
                'due' => $sale->due_amount,
            ]);
    }

    private function sales(ReportPeriod $period): Builder
    {
        return DB::table('sales')->where('status', SaleStatus::Completed->value)->whereBetween('sold_at', $period->datetimeBounds());
    }

    private function items(ReportPeriod $period): Builder
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.sold_at', $period->datetimeBounds());
    }
}
