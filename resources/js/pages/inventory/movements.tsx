import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MovementTable } from '@/features/inventory/movement-table';
import { type StockMovement } from '@/features/products/types';
import AppLayout from '@/layouts/app-layout';
import { type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface MovementsProps {
    movements: Paginated<StockMovement>;
    filters: { type: string; product_id: number | null; from: string; to: string };
    types: { value: string; label: string }[];
}

export default function Movements({ movements, filters, types }: MovementsProps) {
    const [form, setForm] = useState({ type: filters.type, from: filters.from, to: filters.to });

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        const query = Object.fromEntries(
            Object.entries({ ...form, product_id: filters.product_id ?? '' }).filter(([, value]) => value !== '' && value !== null),
        );
        router.get(route('inventory.movements.index'), query, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Stock movements', href: route('inventory.movements.index') }]}>
            <Head title="Stock movements" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="Stock movements" description="The complete, permanent ledger of every stock change." />

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                    <select
                        value={form.type}
                        onChange={(e) => setForm({ ...form, type: e.target.value })}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        aria-label="Movement type"
                    >
                        <option value="">All types</option>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                    <Input
                        type="date"
                        value={form.from}
                        onChange={(e) => setForm({ ...form, from: e.target.value })}
                        className="sm:w-44"
                        aria-label="From"
                    />
                    <Input
                        type="date"
                        value={form.to}
                        onChange={(e) => setForm({ ...form, to: e.target.value })}
                        className="sm:w-44"
                        aria-label="To"
                    />
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <MovementTable movements={movements.data} showProduct />
                <Pagination meta={movements.meta} />
            </div>
        </AppLayout>
    );
}
