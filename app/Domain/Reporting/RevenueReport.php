<?php

namespace App\Domain\Reporting;

use App\Enums\InvoiceLineType;
use App\Enums\SaleStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Revenue by classification, from invoice LINES:
 *
 * - PRODUCT revenue = POS sale lines + service-invoice PRODUCT lines (parts) − sale returns;
 * - SERVICE revenue = service-invoice SERVICE lines + service charges billed on POS sales;
 * - COMBINED revenue = PRODUCT + SERVICE.
 *
 * A service invoice with parts and labour therefore contributes its parts to product revenue and its
 * labour to service revenue, and exactly its total to combined revenue — never counted twice.
 * Line amounts are net of discounts (discount shares), so they add up to what customers were billed.
 * Returns count in the period in which the goods came back.
 */
class RevenueReport
{
    /**
     * @return array{
     *     product: array{pos_sales: string, service_parts: string, returns: string, net: string,
     *                    quantity: array{pos: int, parts: int, returned: int, net: int}, cost: string, gross_profit: string},
     *     service: array{revenue: string, invoices: int, repairs: string, pos_charges: string, pos_sales: int},
     *     combined: string,
     *     documents: array{sales: int, service_invoices: int, sale_returns: int, total: string, matches: bool}
     * }
     */
    public function totals(ReportPeriod $period): array
    {
        $pos = $this->posLines($period)
            ->selectRaw('COALESCE(SUM(sale_items.line_total), 0) as amount, COALESCE(SUM(sale_items.quantity), 0) as quantity, COALESCE(SUM(sale_items.cost_total), 0) as cost')
            ->first();

        $service = $this->serviceLines($period)
            ->selectRaw('service_invoice_items.line_type, COALESCE(SUM(service_invoice_items.line_total), 0) as amount, COALESCE(SUM(service_invoice_items.quantity), 0) as quantity, COALESCE(SUM(service_invoice_items.cost_total), 0) as cost, COUNT(DISTINCT service_invoice_items.service_invoice_id) as invoices')
            ->groupBy('service_invoice_items.line_type')
            ->get()
            ->keyBy('line_type');

        $returns = $this->returnLines($period)
            ->selectRaw('COALESCE(SUM(sale_return_items.amount), 0) as amount, COALESCE(SUM(sale_return_items.quantity), 0) as quantity, COALESCE(SUM(sale_return_items.cost_total), 0) as cost')
            ->first();

        $counter = $this->posServiceLines($period)
            ->selectRaw('COALESCE(SUM(sale_service_charges.line_total), 0) as amount, COUNT(DISTINCT sale_service_charges.sale_id) as sales')
            ->first();

        $parts = $service->get(InvoiceLineType::Product->value);
        $labour = $service->get(InvoiceLineType::Service->value);

        $posAmount = Money::of((string) $pos->amount);
        $partsAmount = Money::of((string) ($parts->amount ?? 0));
        $returnsAmount = Money::of((string) $returns->amount);
        $productNet = Money::sub(Money::add($posAmount, $partsAmount), $returnsAmount);
        $repairs = Money::of((string) ($labour->amount ?? 0));
        $posCharges = Money::of((string) $counter->amount);
        $serviceRevenue = Money::add($repairs, $posCharges);
        $cost = Money::sub(Money::add(Money::of((string) $pos->cost), Money::of((string) ($parts->cost ?? 0))), Money::of((string) $returns->cost));
        $combined = Money::add($productNet, $serviceRevenue);
        $documents = $this->documentTotals($period);

        return [
            'product' => [
                'pos_sales' => $posAmount,
                'service_parts' => $partsAmount,
                'returns' => $returnsAmount,
                'net' => $productNet,
                'quantity' => [
                    'pos' => (int) $pos->quantity,
                    'parts' => (int) ($parts->quantity ?? 0),
                    'returned' => (int) $returns->quantity,
                    'net' => (int) $pos->quantity + (int) ($parts->quantity ?? 0) - (int) $returns->quantity,
                ],
                'cost' => $cost,
                'gross_profit' => Money::sub($productNet, $cost),
            ],
            'service' => [
                'revenue' => $serviceRevenue,
                'invoices' => (int) ($labour->invoices ?? 0),
                'repairs' => $repairs,
                'pos_charges' => $posCharges,
                'pos_sales' => (int) $counter->sales,
            ],
            'combined' => $combined,
            // Independent check from document totals: sales − returns + service invoices.
            'documents' => [...$documents, 'matches' => Money::cmp($documents['total'], $combined) === 0],
        ];
    }

    /**
     * Product / service / combined revenue per day or month.
     *
     * @return list<array{period: string, pos_sales: string, service_parts: string, returns: string, product: string,
     *                    product_quantity: int, service: string, combined: string}>
     */
    public function byPeriod(ReportPeriod $period): array
    {
        $rows = [];
        $row = function (string $key) use (&$rows): void {
            $rows[$key] ??= ['period' => $key, 'pos_sales' => '0.00', 'service_parts' => '0.00', 'returns' => '0.00', 'product_quantity' => 0, 'service' => '0.00'];
        };

        $posBucket = $this->sql($period->bucket('sales.sold_at'));
        foreach ($this->posLines($period)
            ->selectRaw("{$posBucket} as bucket, SUM(sale_items.line_total) as amount, SUM(sale_items.quantity) as quantity")
            ->groupBy(DB::raw($posBucket))
            ->get() as $pos) {
            $row($pos->bucket);
            $rows[$pos->bucket]['pos_sales'] = Money::of((string) $pos->amount);
            $rows[$pos->bucket]['product_quantity'] += (int) $pos->quantity;
        }

        $serviceBucket = $this->sql($period->bucket('service_invoices.invoiced_at'));
        foreach ($this->serviceLines($period)
            ->selectRaw("{$serviceBucket} as bucket, service_invoice_items.line_type, SUM(service_invoice_items.line_total) as amount, SUM(service_invoice_items.quantity) as quantity")
            ->groupBy(DB::raw($serviceBucket), 'service_invoice_items.line_type')
            ->get() as $line) {
            $row($line->bucket);

            if ($line->line_type === InvoiceLineType::Product->value) {
                $rows[$line->bucket]['service_parts'] = Money::of((string) $line->amount);
                $rows[$line->bucket]['product_quantity'] += (int) $line->quantity;
            } else {
                $rows[$line->bucket]['service'] = Money::of((string) $line->amount);
            }
        }

        foreach ($this->posServiceLines($period)
            ->selectRaw("{$posBucket} as bucket, SUM(sale_service_charges.line_total) as amount")
            ->groupBy(DB::raw($posBucket))
            ->get() as $charge) {
            $row($charge->bucket);
            $rows[$charge->bucket]['service'] = Money::add($rows[$charge->bucket]['service'], Money::of((string) $charge->amount));
        }

        $returnBucket = $this->sql($period->bucket('sale_returns.returned_at'));
        foreach ($this->returnLines($period)
            ->selectRaw("{$returnBucket} as bucket, SUM(sale_return_items.amount) as amount, SUM(sale_return_items.quantity) as quantity")
            ->groupBy(DB::raw($returnBucket))
            ->get() as $return) {
            $row($return->bucket);
            $rows[$return->bucket]['returns'] = Money::of((string) $return->amount);
            $rows[$return->bucket]['product_quantity'] -= (int) $return->quantity;
        }

        ksort($rows);

        return array_values(array_map(function (array $r): array {
            $product = Money::sub(Money::add($r['pos_sales'], $r['service_parts']), $r['returns']);

            return [...$r, 'product' => $product, 'combined' => Money::add($product, $r['service'])];
        }, $rows));
    }

    /**
     * PRODUCT revenue per product (POS + service parts − returns), highest net revenue first.
     */
    public function byProduct(ReportPeriod $period, int $perPage = 25): LengthAwarePaginator
    {
        $zero = '0';

        $pos = $this->posLines($period)->selectRaw("sale_items.product_id, sale_items.quantity as pos_qty, sale_items.line_total as pos_amount, {$zero} as parts_qty, {$zero} as parts_amount, {$zero} as returned_qty, {$zero} as returned_amount, sale_items.cost_total as cost");
        $parts = $this->serviceLines($period)
            ->where('service_invoice_items.line_type', InvoiceLineType::Product->value)
            ->selectRaw("service_invoice_items.product_id, {$zero}, {$zero}, service_invoice_items.quantity, service_invoice_items.line_total, {$zero}, {$zero}, service_invoice_items.cost_total");
        $returns = $this->returnLines($period)->selectRaw("sale_return_items.product_id, {$zero}, {$zero}, {$zero}, {$zero}, sale_return_items.quantity, sale_return_items.amount, -sale_return_items.cost_total");

        $lines = $pos->unionAll($parts)->unionAll($returns);

        $query = DB::query()
            ->fromSub($lines, 'lines')
            ->join('products', 'products.id', '=', 'lines.product_id')
            ->groupBy('lines.product_id', 'products.name', 'products.sku')
            ->selectRaw('lines.product_id, products.name, products.sku,
                SUM(lines.pos_qty) as pos_qty, SUM(lines.pos_amount) as pos_amount,
                SUM(lines.parts_qty) as parts_qty, SUM(lines.parts_amount) as parts_amount,
                SUM(lines.returned_qty) as returned_qty, SUM(lines.returned_amount) as returned_amount,
                SUM(lines.pos_amount) + SUM(lines.parts_amount) - SUM(lines.returned_amount) as net_amount,
                SUM(lines.cost) as cost')
            ->orderByDesc('net_amount')
            ->orderBy('products.name');

        // One row per product sold: run the aggregate once instead of once more for the count.
        return AggregatePaginator::paginate($query, $perPage, 'page', fn ($r) => [
            'product_id' => (int) $r->product_id,
            'name' => $r->name,
            'sku' => $r->sku,
            'pos_qty' => (int) $r->pos_qty,
            'pos_amount' => Money::of((string) $r->pos_amount),
            'parts_qty' => (int) $r->parts_qty,
            'parts_amount' => Money::of((string) $r->parts_amount),
            'returned_qty' => (int) $r->returned_qty,
            'returned_amount' => Money::of((string) $r->returned_amount),
            'net_qty' => (int) $r->pos_qty + (int) $r->parts_qty - (int) $r->returned_qty,
            'net_amount' => Money::of((string) $r->net_amount),
            'cost' => Money::of((string) $r->cost),
        ]);
    }

    /**
     * SERVICE revenue per technician; service charges billed at the POS counter form their own row.
     *
     * @return list<array{source: string, technician_id: ?int, technician: ?string, invoices: int, revenue: string}>
     */
    public function serviceByTechnician(ReportPeriod $period): array
    {
        $rows = $this->serviceLines($period)
            ->where('service_invoice_items.line_type', InvoiceLineType::Service->value)
            ->join('service_jobs', 'service_jobs.id', '=', 'service_invoices.service_job_id')
            ->leftJoin('users', 'users.id', '=', 'service_jobs.technician_id')
            ->groupBy('service_jobs.technician_id', 'users.name')
            ->selectRaw('service_jobs.technician_id, users.name as technician, COUNT(DISTINCT service_invoices.id) as invoices, SUM(service_invoice_items.line_total) as revenue')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'source' => 'JOB',
                'technician_id' => $r->technician_id !== null ? (int) $r->technician_id : null,
                'technician' => $r->technician,
                'invoices' => (int) $r->invoices,
                'revenue' => Money::of((string) $r->revenue),
            ])
            ->all();

        $counter = $this->posServiceLines($period)
            ->selectRaw('COUNT(DISTINCT sale_service_charges.sale_id) as invoices, COALESCE(SUM(sale_service_charges.line_total), 0) as revenue')
            ->first();

        if ((int) $counter->invoices > 0) {
            $rows[] = [
                'source' => 'POS',
                'technician_id' => null,
                'technician' => 'Counter sales (POS)',
                'invoices' => (int) $counter->invoices,
                'revenue' => Money::of((string) $counter->revenue),
            ];
            usort($rows, fn (array $a, array $b) => Money::cmp($b['revenue'], $a['revenue']));
        }

        return $rows;
    }

    /**
     * The SERVICE lines themselves (service invoices and POS sale service charges), newest first.
     */
    public function serviceLineDetails(ReportPeriod $period, int $perPage = 25): LengthAwarePaginator
    {
        $jobs = $this->serviceLines($period)
            ->where('service_invoice_items.line_type', InvoiceLineType::Service->value)
            ->join('service_jobs', 'service_jobs.id', '=', 'service_invoices.service_job_id')
            ->join('parties', 'parties.id', '=', 'service_invoices.party_id')
            ->leftJoin('users', 'users.id', '=', 'service_jobs.technician_id')
            ->selectRaw("'JOB' as source")
            ->addSelect([
                'service_invoice_items.id', 'service_invoice_items.description', 'service_invoice_items.line_total',
                'service_invoices.id as invoice_id', 'service_invoices.invoice_no', 'service_invoices.invoiced_at',
                'service_jobs.id as job_id', 'service_jobs.job_no', 'parties.name as customer', 'users.name as technician',
            ]);

        $counter = $this->posServiceLines($period)
            ->leftJoin('parties', 'parties.id', '=', 'sales.party_id')
            ->selectRaw("'POS' as source")
            ->addSelect([
                'sale_service_charges.id', 'sale_service_charges.description', 'sale_service_charges.line_total',
                'sales.id as invoice_id', 'sales.invoice_no', 'sales.sold_at as invoiced_at',
            ])
            ->selectRaw('NULL as job_id, NULL as job_no')
            ->addSelect('parties.name as customer')
            ->selectRaw('NULL as technician');

        return DB::query()
            ->fromSub($jobs->unionAll($counter), 'lines')
            ->orderByDesc('invoiced_at')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: 'lines_page')
            ->withQueryString()
            ->through(fn ($r) => [
                'source' => $r->source,
                'id' => (int) $r->id,
                'description' => $r->description,
                'amount' => Money::of((string) $r->line_total),
                'invoice_id' => (int) $r->invoice_id,
                'invoice_no' => $r->invoice_no,
                'invoiced_at' => CarbonImmutable::parse($r->invoiced_at, config('app.timezone'))->toIso8601String(),
                'job_id' => $r->job_id !== null ? (int) $r->job_id : null,
                'job_no' => $r->job_no,
                'customer' => $r->customer ?? 'Walk-in customer',
                'technician' => $r->technician,
            ]);
    }

    /**
     * Completed POS sale lines in the period.
     */
    private function posLines(ReportPeriod $period): Builder
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.sold_at', $period->datetimeBounds());
    }

    /**
     * Service charges on completed POS sales in the period.
     */
    private function posServiceLines(ReportPeriod $period): Builder
    {
        return DB::table('sale_service_charges')
            ->join('sales', 'sales.id', '=', 'sale_service_charges.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.sold_at', $period->datetimeBounds());
    }

    /**
     * Completed service invoice lines (PRODUCT and SERVICE) in the period.
     */
    private function serviceLines(ReportPeriod $period): Builder
    {
        return DB::table('service_invoice_items')
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_items.service_invoice_id')
            ->where('service_invoices.status', SaleStatus::Completed->value)
            ->whereBetween('service_invoices.invoiced_at', $period->datetimeBounds());
    }

    /**
     * Sale return lines in the period.
     */
    private function returnLines(ReportPeriod $period): Builder
    {
        return DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.status', 'COMPLETED')
            ->whereBetween('sale_returns.returned_at', $period->datetimeBounds());
    }

    /**
     * @return array{sales: int, service_invoices: int, sale_returns: int, total: string}
     */
    private function documentTotals(ReportPeriod $period): array
    {
        $bounds = $period->datetimeBounds();

        $sales = DB::table('sales')->where('status', SaleStatus::Completed->value)->whereBetween('sold_at', $bounds)
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(total), 0) as total')->first();
        $invoices = DB::table('service_invoices')->where('status', SaleStatus::Completed->value)->whereBetween('invoiced_at', $bounds)
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(total), 0) as total')->first();
        $returns = DB::table('sale_returns')->where('status', 'COMPLETED')->whereBetween('returned_at', $bounds)
            ->selectRaw('COUNT(*) as documents, COALESCE(SUM(subtotal), 0) as total')->first();

        return [
            'sales' => (int) $sales->documents,
            'service_invoices' => (int) $invoices->documents,
            'sale_returns' => (int) $returns->documents,
            'total' => Money::sub(Money::add(Money::of((string) $sales->total), Money::of((string) $invoices->total)), Money::of((string) $returns->total)),
        ];
    }

    private function sql(Expression $expression): string
    {
        return (string) $expression->getValue(DB::connection()->getQueryGrammar());
    }
}
