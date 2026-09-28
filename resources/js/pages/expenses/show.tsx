import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Expense, type ExpenseAudit } from '@/features/expenses/types';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney } from '@/lib/format';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface ShowExpenseProps {
    expense: { data: Expense };
    types: Record<string, string>;
}

const fieldLabels: Record<string, string> = {
    expense_type_id: 'Type',
    amount: 'Amount',
    expense_date: 'Date',
    payment_method: 'Method',
    reference: 'Reference',
    notes: 'Notes',
};

function ChangeValue({ field, value, types }: { field: string; value: string | null; types: Record<string, string> }) {
    if (value === null || value === '') return <span className="text-muted-foreground">empty</span>;
    if (field === 'expense_type_id') return <>{types[value] ?? `#${value}`}</>;
    if (field === 'amount') return <>{formatMoney(value)}</>;

    return <>{value}</>;
}

function AuditEntry({ audit, types }: { audit: ExpenseAudit; types: Record<string, string> }) {
    return (
        <li className="px-4 py-2">
            <div className="flex justify-between gap-2">
                <span className="font-medium">{audit.action_label}</span>
                <span className="text-muted-foreground text-xs">{formatDateTime(audit.created_at)}</span>
            </div>
            <div className="text-muted-foreground text-xs">{audit.created_by ?? 'System'}</div>
            {audit.reason && <div className="text-xs">Reason: {audit.reason}</div>}
            {audit.changes && (
                <ul className="mt-1 space-y-0.5 text-xs">
                    {Object.entries(audit.changes).map(([field, change]) => (
                        <li key={field}>
                            {fieldLabels[field] ?? field}: <ChangeValue field={field} value={change.from} types={types} /> →{' '}
                            <ChangeValue field={field} value={change.to} types={types} />
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function ShowExpense({ expense: { data: expense }, types }: ShowExpenseProps) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [voiding, setVoiding] = useState(false);
    const form = useForm({ reason: '' });
    const isVoid = expense.status === 'VOID';

    const submitVoid: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('expenses.void', expense.id), { preserveScroll: true, onSuccess: () => setVoiding(false) });
    };

    const rows: [string, string][] = [
        ['Date', expense.expense_date],
        ['Type', expense.type?.name ?? '—'],
        ['Payment method', expense.payment_method_label],
        ['Reference', expense.reference ?? '—'],
        ['Recorded by', expense.created_by ?? '—'],
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Expenses', href: route('expenses.index') },
                { title: expense.expense_no, href: route('expenses.show', expense.id) },
            ]}
        >
            <Head title={expense.expense_no} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 className="flex items-center gap-2 font-mono text-xl font-semibold">
                            {expense.expense_no}
                            <Badge variant={isVoid ? 'destructive' : 'secondary'}>{expense.status_label}</Badge>
                        </h2>
                        <p className={isVoid ? 'text-muted-foreground text-2xl font-semibold line-through' : 'text-2xl font-semibold'}>
                            {formatMoney(expense.amount)}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {expense.can.update && (
                            <Button asChild>
                                <Link href={route('expenses.edit', expense.id)}>Edit</Link>
                            </Button>
                        )}
                        {expense.can.void && (
                            <Button variant="outline" className="text-destructive" onClick={() => setVoiding(true)}>
                                Void expense
                            </Button>
                        )}
                    </div>
                </div>

                {errors.expense && <p className="text-destructive text-sm">{errors.expense}</p>}

                {isVoid && (
                    <p className="border-destructive/40 bg-destructive/10 rounded-md border px-3 py-2 text-sm">
                        Voided {formatDateTime(expense.voided_at)}
                        {expense.voided_by && ` by ${expense.voided_by}`} — {expense.void_reason}. It is excluded from all totals.
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="space-y-4 rounded-lg border p-4">
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            {rows.map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-muted-foreground text-xs">{label}</dt>
                                    <dd className="font-medium">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        {expense.notes && <p className="border-t pt-3 text-sm whitespace-pre-line">{expense.notes}</p>}
                    </div>

                    <div className="rounded-lg border">
                        <h3 className="border-b px-4 py-2 text-sm font-medium">History</h3>
                        <ol className="divide-y text-sm">
                            {expense.audits?.map((audit) => <AuditEntry key={audit.id} audit={audit} types={types} />)}
                        </ol>
                    </div>
                </div>
            </div>

            <Dialog open={voiding} onOpenChange={setVoiding}>
                <DialogContent>
                    <DialogTitle>Void {expense.expense_no}</DialogTitle>
                    <DialogDescription>
                        The expense is kept for the record but no longer counts in any total. This cannot be undone.
                    </DialogDescription>
                    <form onSubmit={submitVoid} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Reason</Label>
                            <Input id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                            <InputError message={form.errors.reason} />
                        </div>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setVoiding(false)}>
                                Back
                            </Button>
                            <Button type="submit" variant="destructive" disabled={form.processing}>
                                Void expense
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
