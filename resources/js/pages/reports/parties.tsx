import { Pagination } from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { type SelectOption } from '@/features/purchasing/types';
import { type Outstanding } from '@/features/reports/outstanding';
import { ReportFilters } from '@/features/reports/report-filters';
import { ReportPage, td, tdRight, th, thRight } from '@/features/reports/report-page';
import { StatCard } from '@/features/reports/stat-card';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Paginator } from '@/types';
import { Link } from '@inertiajs/react';

interface PartyReportProps {
    filters: { from: string; to: string; group_by: string; type: string; side: string; q: string };
    outstanding: Outstanding;
    parties: Paginator<{
        id: number;
        name: string;
        type: string;
        phone: string | null;
        opening: string;
        transactions: string;
        payments: string;
        returns: string;
        adjustments: string;
        entries: number;
        closing: string;
    }>;
    types: SelectOption[];
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

/**
 * Signed ledger amount: positive = the party owes the shop (receivable), negative = the shop owes the party.
 */
function Balance({ value }: { value: string }) {
    const negative = value.startsWith('-');
    const zero = /^-?0(\.0+)?$/.test(value);

    if (zero) return <span className="text-muted-foreground">—</span>;

    return <span className={negative ? 'text-destructive' : undefined}>{negative ? `(${formatMoney(value.slice(1))})` : formatMoney(value)}</span>;
}

/**
 * T045 — outstanding balances (customer dues and credit, supplier payables and advances) and each party's ledger for the period.
 */
export default function PartyReport({ filters, outstanding, parties, types }: PartyReportProps) {
    const range = { from: filters.from, to: filters.to };

    return (
        <ReportPage title="Party ledger report" description="From the party ledger" routeName="reports.parties" range={range}>
            <ReportFilters routeName="reports.parties" filters={filters}>
                {(values, set) => (
                    <>
                        <Input
                            value={String(values.q ?? '')}
                            onChange={(e) => set('q', e.target.value)}
                            placeholder="Name or phone"
                            className="sm:w-48"
                            aria-label="Search parties"
                        />
                        <select
                            className={selectClass}
                            value={String(values.type ?? '')}
                            onChange={(e) => set('type', e.target.value)}
                            aria-label="Party type"
                        >
                            <option value="">All parties</option>
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                        <select
                            className={selectClass}
                            value={String(values.side ?? '')}
                            onChange={(e) => set('side', e.target.value)}
                            aria-label="Balance"
                        >
                            <option value="">Any closing balance</option>
                            <option value="receivable">Receivable (owes the shop)</option>
                            <option value="payable">Payable (shop owes)</option>
                        </select>
                    </>
                )}
            </ReportFilters>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Customer dues (now)"
                    value={formatMoney(outstanding.customer_receivable)}
                    sub={`${outstanding.customer_receivable_parties} customer(s) owe the shop`}
                />
                <StatCard
                    label="Supplier payables (now)"
                    value={formatMoney(outstanding.supplier_payable)}
                    sub={`The shop owes ${outstanding.supplier_payable_parties} supplier(s)`}
                />
                <StatCard
                    label="Customer credit (now)"
                    value={formatMoney(outstanding.customer_credit)}
                    sub={`Store credit / advances held for ${outstanding.customer_credit_parties} customer(s)`}
                />
                <StatCard
                    label="Supplier advances (now)"
                    value={formatMoney(outstanding.supplier_advance)}
                    sub={`${outstanding.supplier_advance_parties} supplier(s) hold the shop's money`}
                />
            </div>

            <p className="text-muted-foreground text-xs">
                Amounts are signed from the shop's side: positive increases what the party owes the shop; (bracketed) amounts are owed by the shop.
                Transactions = sales, service invoices, purchases; payments include refunds and advances.
            </p>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className={th}>Party</th>
                            <th className={thRight}>Opening</th>
                            <th className={thRight}>Transactions</th>
                            <th className={thRight}>Payments</th>
                            <th className={thRight}>Returns</th>
                            <th className={thRight}>Adjustments</th>
                            <th className={thRight}>Closing</th>
                        </tr>
                    </thead>
                    <tbody>
                        {parties.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-muted-foreground px-3 py-6 text-center">
                                    No party balances or activity.
                                </td>
                            </tr>
                        )}
                        {parties.data.map((party) => (
                            <tr key={party.id} className="border-t">
                                <td className={td}>
                                    <Link href={route('parties.show', { party: party.id, ...range })} className="font-medium hover:underline">
                                        {party.name}
                                    </Link>
                                    <div className="text-muted-foreground text-xs">
                                        {party.type}
                                        {party.phone && ` · ${party.phone}`} · {party.entries} entr(ies)
                                    </div>
                                </td>
                                <td className={tdRight}>
                                    <Balance value={party.opening} />
                                </td>
                                <td className={tdRight}>
                                    <Balance value={party.transactions} />
                                </td>
                                <td className={tdRight}>
                                    <Balance value={party.payments} />
                                </td>
                                <td className={tdRight}>
                                    <Balance value={party.returns} />
                                </td>
                                <td className={tdRight}>
                                    <Balance value={party.adjustments} />
                                </td>
                                <td className={cn(tdRight, 'font-medium')}>
                                    <Balance value={party.closing} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination meta={parties} />
        </ReportPage>
    );
}
