import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PartyBalance } from '@/features/parties/balance';
import { type Party, type SelectOption } from '@/features/purchasing/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface PartiesIndexProps {
    parties: Paginated<Party>;
    filters: { search: string; type: string; balance: string; status: string };
    types: SelectOption[];
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function PartiesIndex({ parties, filters, types }: PartiesIndexProps) {
    const can = useCan();
    const [form, setForm] = useState(filters);

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('parties.index'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Parties', href: route('parties.index') }]}>
            <Head title="Parties" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Parties" description="Suppliers and customers with their ledger balances." />
                    {can('parties.manage') && (
                        <Button asChild>
                            <Link href={route('parties.create')}>
                                <Plus className="size-4" /> New party
                            </Link>
                        </Button>
                    )}
                </div>

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Input
                        placeholder="Name or phone"
                        value={form.search}
                        onChange={(e) => setForm({ ...form, search: e.target.value })}
                        className="sm:max-w-xs"
                    />
                    <select className={selectClass} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} aria-label="Type">
                        <option value="">All types</option>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={form.balance}
                        onChange={(e) => setForm({ ...form, balance: e.target.value })}
                        aria-label="Balance"
                    >
                        <option value="">Any balance</option>
                        <option value="payable">Payable</option>
                        <option value="receivable">Receivable / advance</option>
                        <option value="settled">Settled</option>
                    </select>
                    <select
                        className={selectClass}
                        value={form.status}
                        onChange={(e) => setForm({ ...form, status: e.target.value })}
                        aria-label="Status"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Name</th>
                                <th className="px-4 py-3 font-medium">Type</th>
                                <th className="px-4 py-3 font-medium">Phone</th>
                                <th className="px-4 py-3 text-right font-medium">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            {parties.data.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-muted-foreground px-4 py-8 text-center">
                                        No parties found.
                                    </td>
                                </tr>
                            )}
                            {parties.data.map((party) => (
                                <tr key={party.id} className="hover:bg-muted/30 border-t">
                                    <td className="px-4 py-3">
                                        <Link href={route('parties.show', party.id)} className="font-medium hover:underline">
                                            {party.name}
                                        </Link>
                                        {!party.is_active && (
                                            <Badge variant="outline" className="ml-2">
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">{party.type_label}</td>
                                    <td className="px-4 py-3">{party.phone ?? '—'}</td>
                                    <td className="px-4 py-3 text-right">
                                        <PartyBalance balance={party.balance} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={parties.meta} />
            </div>
        </AppLayout>
    );
}
