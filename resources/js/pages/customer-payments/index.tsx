import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { type Payment } from '@/features/purchasing/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney } from '@/lib/format';
import { type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

export default function CustomerPaymentsIndex({ payments }: { payments: Paginated<Payment> }) {
    const can = useCan();

    return (
        <AppLayout breadcrumbs={[{ title: 'Customer payments', href: route('customer-payments.index') }]}>
            <Head title="Customer payments" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Customer payments" description="Money received for sales, at the counter and against dues." />
                    {can('sales.collect') && (
                        <Button asChild>
                            <Link href={route('customer-payments.create')}>Collect due</Link>
                        </Button>
                    )}
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Payment</th>
                                <th className="px-4 py-3 font-medium">Customer</th>
                                <th className="px-4 py-3 font-medium">Method</th>
                                <th className="px-4 py-3 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.data.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-muted-foreground px-4 py-8 text-center">
                                        No customer payments yet.
                                    </td>
                                </tr>
                            )}
                            {payments.data.map((payment) => (
                                <tr key={payment.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route('customer-payments.show', payment.id)}
                                            className="font-mono font-medium whitespace-nowrap hover:underline"
                                        >
                                            {payment.payment_no}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">{formatDateTime(payment.paid_at)}</div>
                                    </td>
                                    <td className="px-4 py-3">{payment.party?.name ?? 'Walk-in customer'}</td>
                                    <td className="px-4 py-3">{payment.method_label}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(payment.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={payments.meta} />
            </div>
        </AppLayout>
    );
}
