import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type Expense } from '@/features/expenses/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Option, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface ExpensesIndexProps {
    expenses: Paginated<Expense>;
    filteredTotal: string;
    types: Option[];
    filters: { q: string; expense_type_id: string | number; status: string; from: string; to: string };
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function ExpensesIndex({ expenses, filteredTotal, types, filters }: ExpensesIndexProps) {
    const can = useCan();
    const [form, setForm] = useState({ ...filters, expense_type_id: String(filters.expense_type_id ?? '') });

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('expenses.index'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Expenses', href: route('expenses.index') }]}>
            <Head title="Expenses" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Expenses" description="Money spent by the shop. Voided expenses are kept for the record but never counted." />
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={route('expenses.report')}>Report</Link>
                        </Button>
                        {can('expenses.manage') && (
                            <Button asChild>
                                <Link href={route('expenses.create')}>Record expense</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Input
                        placeholder="Expense no. or reference"
                        value={form.q}
                        onChange={(e) => setForm({ ...form, q: e.target.value })}
                        className="sm:max-w-52"
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
                        value={form.status}
                        onChange={(e) => setForm({ ...form, status: e.target.value })}
                        aria-label="Status"
                    >
                        <option value="">All statuses</option>
                        <option value="RECORDED">Recorded</option>
                        <option value="VOID">Void</option>
                    </select>
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
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Expense</th>
                                <th className="px-4 py-3 font-medium">Date</th>
                                <th className="px-4 py-3 font-medium">Type</th>
                                <th className="px-4 py-3 font-medium">Method</th>
                                <th className="px-4 py-3 font-medium">Reference</th>
                                <th className="px-4 py-3 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {expenses.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No expenses found.
                                    </td>
                                </tr>
                            )}
                            {expenses.data.map((expense) => (
                                <tr
                                    key={expense.id}
                                    className={cn('hover:bg-muted/30 border-t', expense.status === 'VOID' && 'text-muted-foreground')}
                                >
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route('expenses.show', expense.id)}
                                            className="font-mono font-medium whitespace-nowrap hover:underline"
                                        >
                                            {expense.expense_no}
                                        </Link>
                                        {expense.status === 'VOID' && (
                                            <Badge variant="outline" className="ml-2">
                                                Void
                                            </Badge>
                                        )}
                                        {expense.created_by && <div className="text-muted-foreground text-xs">{expense.created_by}</div>}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">{expense.expense_date}</td>
                                    <td className="px-4 py-3">{expense.type?.name}</td>
                                    <td className="px-4 py-3">{expense.payment_method_label}</td>
                                    <td className="px-4 py-3">{expense.reference ?? '—'}</td>
                                    <td className={cn('px-4 py-3 text-right tabular-nums', expense.status === 'VOID' && 'line-through')}>
                                        {formatMoney(expense.amount)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="bg-muted/30 border-t font-semibold">
                                <td colSpan={5} className="px-4 py-3 text-right">
                                    Total (excluding void)
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatMoney(filteredTotal)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <Pagination meta={expenses.meta} />
            </div>
        </AppLayout>
    );
}
