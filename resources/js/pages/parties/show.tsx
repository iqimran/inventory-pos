import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PartyBalance } from '@/features/parties/balance';
import { type Party } from '@/features/purchasing/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney } from '@/lib/format';
import { type PageMeta } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface StatementEntry {
    id: number;
    occurred_at: string;
    entry_type: string;
    entry_type_label: string;
    description: string | null;
    debit: string;
    credit: string;
    balance: string;
    reference_type: string | null;
    reference_id: number | null;
    created_by: string | null;
}

interface ShowPartyProps {
    party: { data: Party };
    statement: {
        from: string;
        to: string;
        opening_balance: string;
        closing_balance: string;
        total_debit: string;
        total_credit: string;
        entries: StatementEntry[];
        page_opening: string;
        pagination: (PageMeta & { current_page: number }) | null;
    };
    summary: { balance: string; available_advance: string; due_purchases_count: number; due_purchases_amount: string };
}

function referenceLink(entry: StatementEntry): string | null {
    if (!entry.reference_id) return null;
    if (entry.reference_type === 'purchase') return route('purchases.show', entry.reference_id);
    if (entry.reference_type === 'service_invoice') return route('service-invoices.show', entry.reference_id);
    if (entry.reference_type === 'payment') return route('supplier-payments.show', entry.reference_id);
    return null;
}

export default function ShowParty({ party: { data: party }, statement, summary }: ShowPartyProps) {
    const can = useCan();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [range, setRange] = useState({ from: statement.from, to: statement.to });
    const firstPage = (statement.pagination?.current_page ?? 1) === 1;
    const lastPage = !statement.pagination || statement.pagination.current_page >= statement.pagination.last_page;
    const [adjusting, setAdjusting] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const adjustment = useForm({ side: 'credit', amount: '', reason: '' });

    const applyRange: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('parties.show', party.id), range, { preserveState: true, replace: true });
    };

    const submitAdjustment: FormEventHandler = (e) => {
        e.preventDefault();
        adjustment.post(route('parties.ledger-adjustments.store', party.id), {
            preserveScroll: true,
            onSuccess: () => {
                setAdjusting(false);
                adjustment.reset();
            },
        });
    };

    const isSupplier = party.type !== 'CUSTOMER';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Parties', href: route('parties.index') },
                { title: party.name, href: route('parties.show', party.id) },
            ]}
        >
            <Head title={party.name} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-1">
                        <h2 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            {party.name}
                            <Badge variant="outline">{party.type_label}</Badge>
                            {!party.is_active && <Badge variant="outline">Inactive</Badge>}
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {[party.phone, party.email, party.address].filter(Boolean).join(' · ') || 'No contact details'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {isSupplier && can('purchases.create') && (
                            <Button asChild>
                                <Link href={route('purchases.create', { party_id: party.id })}>New purchase</Link>
                            </Button>
                        )}
                        {isSupplier && can('payments.create') && (
                            <>
                                <Button variant="secondary" asChild>
                                    <Link href={route('supplier-payments.create', { party_id: party.id })}>Pay supplier</Link>
                                </Button>
                                <Button variant="secondary" asChild>
                                    <Link href={route('supplier-payments.create', { party_id: party.id, mode: 'advance' })}>Pay advance</Link>
                                </Button>
                            </>
                        )}
                        {can('ledger.adjust') && (
                            <Button variant="outline" onClick={() => setAdjusting(true)}>
                                Adjust ledger
                            </Button>
                        )}
                        {can('parties.manage') && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={route('parties.edit', party.id)}>Edit</Link>
                                </Button>
                                <Button variant="destructive" onClick={() => setDeleting(true)}>
                                    Delete
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <InputError message={errors.record} />

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Current balance</CardTitle>
                        </CardHeader>
                        <CardContent className="text-lg font-semibold">
                            <PartyBalance balance={summary.balance} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Unapplied supplier advance</CardTitle>
                        </CardHeader>
                        <CardContent className="text-lg font-semibold tabular-nums">{formatMoney(summary.available_advance)}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Purchases with dues</CardTitle>
                        </CardHeader>
                        <CardContent className="text-lg font-semibold tabular-nums">
                            {summary.due_purchases_count} · {formatMoney(summary.due_purchases_amount)}
                        </CardContent>
                    </Card>
                </div>

                <section className="space-y-3">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <h3 className="font-medium">Statement</h3>
                        <form onSubmit={applyRange} className="flex flex-wrap items-end gap-2">
                            <Input
                                type="date"
                                value={range.from}
                                onChange={(e) => setRange({ ...range, from: e.target.value })}
                                className="w-40"
                                aria-label="From"
                            />
                            <Input
                                type="date"
                                value={range.to}
                                onChange={(e) => setRange({ ...range, to: e.target.value })}
                                className="w-40"
                                aria-label="To"
                            />
                            <Button type="submit" variant="secondary">
                                Show
                            </Button>
                        </form>
                    </div>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Date</th>
                                    <th className="px-4 py-3 font-medium">Transaction</th>
                                    <th className="px-4 py-3 text-right font-medium">Debit</th>
                                    <th className="px-4 py-3 text-right font-medium">Credit</th>
                                    <th className="px-4 py-3 text-right font-medium">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className="bg-muted/20 border-t">
                                    <td className="px-4 py-2" colSpan={4}>
                                        {firstPage ? `Opening balance on ${statement.from}` : 'Balance brought forward'}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        <PartyBalance balance={firstPage ? statement.opening_balance : statement.page_opening} />
                                    </td>
                                </tr>
                                {statement.entries.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground px-4 py-6 text-center">
                                            No transactions in this period.
                                        </td>
                                    </tr>
                                )}
                                {statement.entries.map((entry) => {
                                    const link = referenceLink(entry);

                                    return (
                                        <tr key={entry.id} className="border-t">
                                            <td className="px-4 py-2 whitespace-nowrap">{formatDateTime(entry.occurred_at)}</td>
                                            <td className="px-4 py-2">
                                                <div>{entry.entry_type_label}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {link ? (
                                                        <Link href={link} className="hover:underline">
                                                            {entry.description}
                                                        </Link>
                                                    ) : (
                                                        entry.description
                                                    )}
                                                    {entry.created_by && ` · ${entry.created_by}`}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {entry.debit !== '0.00' ? formatMoney(entry.debit) : ''}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {entry.credit !== '0.00' ? formatMoney(entry.credit) : ''}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                <PartyBalance balance={entry.balance} />
                                            </td>
                                        </tr>
                                    );
                                })}
                                {!lastPage && statement.entries.length > 0 && (
                                    <tr className="bg-muted/20 border-t">
                                        <td className="px-4 py-2" colSpan={4}>
                                            Balance carried forward
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            <PartyBalance balance={statement.entries[statement.entries.length - 1].balance} />
                                        </td>
                                    </tr>
                                )}
                                <tr className="bg-muted/20 border-t font-medium">
                                    <td className="px-4 py-2" colSpan={2}>
                                        Closing balance on {statement.to}
                                        {!lastPage && <span className="text-muted-foreground font-normal"> (whole period)</span>}
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums">{formatMoney(statement.total_debit)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{formatMoney(statement.total_credit)}</td>
                                    <td className="px-4 py-2 text-right">
                                        <PartyBalance balance={statement.closing_balance} />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    {statement.pagination && <Pagination meta={statement.pagination} />}
                    <p className="text-muted-foreground text-xs">
                        Debit increases what the party owes the shop; credit increases what the shop owes the party.
                    </p>
                </section>
            </div>

            <Dialog open={adjusting} onOpenChange={setAdjusting}>
                <DialogContent>
                    <DialogTitle>Manual ledger adjustment</DialogTitle>
                    <DialogDescription>Recorded permanently with your name and reason.</DialogDescription>
                    <form onSubmit={submitAdjustment} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="side">Direction</Label>
                            <select
                                id="side"
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                value={adjustment.data.side}
                                onChange={(e) => adjustment.setData('side', e.target.value)}
                            >
                                <option value="credit">Credit — shop owes the party more</option>
                                <option value="debit">Debit — party owes the shop more</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="amount">Amount</Label>
                            <Input
                                id="amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={adjustment.data.amount}
                                onChange={(e) => adjustment.setData('amount', e.target.value)}
                                required
                            />
                            <InputError message={adjustment.errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Reason</Label>
                            <Input
                                id="reason"
                                value={adjustment.data.reason}
                                onChange={(e) => adjustment.setData('reason', e.target.value)}
                                required
                            />
                            <InputError message={adjustment.errors.reason} />
                        </div>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setAdjusting(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={adjustment.processing}>
                                Record adjustment
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={`Delete ${party.name}?`}
                description="Parties with any ledger history cannot be deleted; deactivate them instead."
                confirmLabel="Delete"
                destructive
                onConfirm={() => router.delete(route('parties.destroy', party.id), { onFinish: () => setDeleting(false) })}
            />
        </AppLayout>
    );
}
