import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { MovementTable } from '@/features/inventory/movement-table';
import { type StockMovement } from '@/features/products/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

export default function AdjustmentsIndex({ adjustments }: { adjustments: Paginated<StockMovement> }) {
    const can = useCan();

    return (
        <AppLayout breadcrumbs={[{ title: 'Stock adjustments', href: route('inventory.adjustments.index') }]}>
            <Head title="Stock adjustments" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Stock adjustments"
                        description="Manual stock corrections. Adjustments are permanent; fix a mistake with a reverse adjustment."
                    />
                    {can('inventory.adjust') && (
                        <Button asChild>
                            <Link href={route('inventory.adjustments.create')}>
                                <Plus className="size-4" /> New adjustment
                            </Link>
                        </Button>
                    )}
                </div>
                <MovementTable movements={adjustments.data} showProduct />
                <Pagination meta={adjustments.meta} />
            </div>
        </AppLayout>
    );
}
