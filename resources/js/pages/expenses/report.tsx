import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { type Expense } from '@/features/expenses/types';
import { ReportNav } from '@/features/reports/report-nav';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { type Option, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface ExpenseReportProps {
    report: {
        from: string;
        to: string;
        group_by: 'day' | 'month';
        total: string;
        count: number;
        by_type: { expense_type_id: number; name: string; count: number; total: string; share: string }[];
        by_period: { period: string; count: number; total: string }[];
    };
    types: Option[];
    filters: { expense_type_id: number | string };
    expenses: Paginated<Expense>;
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function ExpenseReport({ report, types, filters, expenses }: ExpenseReportProps) {
    const can = useCan();
    const [form, setForm] = useState({
        from: report.from,
        to: report.to,
        expense_type_id: String(filters.expense_type_id ?? ''),
        group_by: report.group_by,
    });

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('expenses.report'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    const listLink = (extra: Record<string, string | number> = {}) =>
        route('expenses.index', { from: report.from, to: report.to, status: 'RECORDED', ...extra });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Expenses', href: route('expenses.index') },
                { title: 'Report', href: route('expenses.report') },
            ]}
        >
            <Head title="Expense report" />
            <div className="space-y-6 p-4 md:p-6">
                {can('reports.view') && <ReportNav current="expenses.report" range={{ from: report.from, to: report.to }} />}
                <Heading title="Expense report" description="Totals by expense type and by date. Voided expenses are excluded." />

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <Input
                        type="date"
                        value={form.from}
                        onChange={(e) => setForm({ ...form, from: e.target.value })}
                        className="sm:w-40"
                        aria-label="From"
                    />
                    <Input
                        type="date"
                        value={form.to}
                        onChange={(e) => setForm({ ...form, to: e.target.value })}
                        className="sm:w-40"
                        aria-label="To"
                    />
                    <select
                        className={selectClass}
                        value={form.expense_type_id}
                        onChange={(e) => setForm({ ...form, expense_type_id: e.target.value })}
                        aria-label="Expense type"
                    >
                        <option value="">All types</option>
                        {types.map((type) => (
                            <option key={type.id} value={type.id}>
                                {type.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={form.group_by}
                        onChange={(e) => setForm({ ...form, group_by: e.target.value as 'day' | 'month' })}
                        aria-label="Group by"
                    >
                        <option value="day">By day</option>
                        <option value="month">By month</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        Apply
                    </Button>
                </form>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="text-muted-foreground text-xs">Total expenses</div>
                            <div className="text-2xl font-semibold tabular-nums">{formatMoney(report.total)}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="text-muted-foreground text-xs">Entries</div>
                            <div className="text-2xl font-semibold tabular-nums">{report.count}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="text-muted-foreground text-xs">Period</div>
                            <div className="text-sm font-medium">
                                {report.from} → {report.to}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-2">
                        <h3 className="font-medium">By expense type</h3>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">Type</th>
                                        <th className="px-4 py-2 text-right font-medium">Entries</th>
                                        <th className="px-4 py-2 text-right font-medium">Total</th>
                                        <th className="px-4 py-2 text-right font-medium">Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.by_type.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="text-muted-foreground px-4 py-6 text-center">
                                                No expenses in this period.
                                            </td>
                                        </tr>
                                    )}
                                    {report.by_type.map((row) => (
                                        <tr key={row.expense_type_id} className="border-t">
                                            <td className="px-4 py-2">
                                                <Link href={listLink({ expense_type_id: row.expense_type_id })} className="hover:underline">
                                                    {row.name}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">{row.count}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{formatMoney(row.total)}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{row.share}%</td>
                                        </tr>
                                    ))}
                                </tbody>
                                {report.by_type.length > 0 && (
                                    <tfoot>
                                        <tr className="bg-muted/30 border-t font-semibold">
                                            <td className="px-4 py-2">Total</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{report.count}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{formatMoney(report.total)}</td>
                                            <td className="px-4 py-2" />
                                        </tr>
                                    </tfoot>
                                )}
                            </table>
                        </div>
                    </section>

                    <section className="space-y-2">
                        <h3 className="font-medium">By {report.group_by === 'month' ? 'month' : 'date'}</h3>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">{report.group_by === 'month' ? 'Month' : 'Date'}</th>
                                        <th className="px-4 py-2 text-right font-medium">Entries</th>
                                        <th className="px-4 py-2 text-right font-medium">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.by_period.length === 0 && (
                                        <tr>
                                            <td colSpan={3} className="text-muted-foreground px-4 py-6 text-center">
                                                No expenses in this period.
                                            </td>
                                        </tr>
                                    )}
                                    {report.by_period.map((row) => (
                                        <tr key={row.period} className="border-t">
                                            <td className="px-4 py-2 tabular-nums">{row.period}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{row.count}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{formatMoney(row.total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <section className="space-y-2">
                    <h3 className="font-medium">Expenses</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Date</th>
                                    <th className="px-4 py-2 font-medium">Expense</th>
                                    <th className="px-4 py-2 font-medium">Type</th>
                                    <th className="px-4 py-2 font-medium">Method</th>
                                    <th className="px-4 py-2 font-medium">Reference</th>
                                    <th className="px-4 py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {expenses.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground px-4 py-6 text-center">
                                            No expenses in this period.
                                        </td>
                                    </tr>
                                )}
                                {expenses.data.map((expense) => (
                                    <tr key={expense.id} className="border-t">
                                        <td className="px-4 py-2 tabular-nums">{expense.expense_date}</td>
                                        <td className="px-4 py-2">
                                            <Link href={route('expenses.show', expense.id)} className="font-mono hover:underline">
                                                {expense.expense_no}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2">{expense.type?.name}</td>
                                        <td className="px-4 py-2">{expense.payment_method_label}</td>
                                        <td className="px-4 py-2">{expense.reference ?? '—'}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{formatMoney(expense.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination meta={expenses.meta} />
                </section>
            </div>
        </AppLayout>
    );
}
