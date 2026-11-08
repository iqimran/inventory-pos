import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PaymentStatusBadge } from '@/features/purchasing/status-badge';
import { type Purchase } from '@/features/purchasing/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { type Option, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface PurchasesIndexProps {
    purchases: Paginated<Purchase>;
    filters: { q: string; party_id: number | null; status: string; from: string; to: string };
    suppliers: Option[];
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function PurchasesIndex({ purchases, filters, suppliers }: PurchasesIndexProps) {
    const can = useCan();
    const [form, setForm] = useState({ ...filters, party_id: filters.party_id ? String(filters.party_id) : '' });

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('purchases.index'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Purchases', href: route('purchases.index') }]}>
            <Head title="Purchases" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Purchases" description="Stock bought from suppliers, with payment status." />
                    {can('purchases.create') && (
                        <Button asChild>
                            <Link href={route('purchases.create')}>
                                <Plus className="size-4" /> New purchase
                            </Link>
                        </Button>
                    )}
                </div>

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Input
                        placeholder="Purchase or invoice no."
                        value={form.q}
                        onChange={(e) => setForm({ ...form, q: e.target.value })}
                        className="sm:max-w-52"
                    />
                    <select
                        className={selectClass}
                        value={form.party_id}
                        onChange={(e) => setForm({ ...form, party_id: e.target.value })}
                        aria-label="Supplier"
                    >
                        <option value="">All suppliers</option>
                        {suppliers.map((supplier) => (
                            <option key={supplier.id} value={supplier.id}>
                                {supplier.name}
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
                        <option value="PAID">Paid</option>
                        <option value="PARTIAL">Partial</option>
                        <option value="DUE">Due</option>
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
                                <th className="px-4 py-3 font-medium">Purchase</th>
                                <th className="px-4 py-3 font-medium">Supplier</th>
                                <th className="px-4 py-3 text-right font-medium">Total</th>
                                <th className="px-4 py-3 text-right font-medium">Paid</th>
                                <th className="px-4 py-3 text-right font-medium">Due</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {purchases.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No purchases found.
                                    </td>
                                </tr>
                            )}
                            {purchases.data.map((purchase) => (
                                <tr key={purchase.id} className="hover:bg-muted/30 border-t">
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route('purchases.show', purchase.id)}
                                            className="font-mono font-medium whitespace-nowrap hover:underline"
                                        >
                                            {purchase.purchase_no}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {purchase.purchase_date}
                                            {purchase.supplier_invoice_no && ` · Inv ${purchase.supplier_invoice_no}`}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{purchase.party?.name}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(purchase.total)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(purchase.paid_amount)}</td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">{formatMoney(purchase.due_amount)}</td>
                                    <td className="px-4 py-3">
                                        <PaymentStatusBadge status={purchase.payment_status} label={purchase.payment_status_label} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={purchases.meta} />
            </div>
        </AppLayout>
    );
}
