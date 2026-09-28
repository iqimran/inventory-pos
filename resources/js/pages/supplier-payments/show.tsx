import { Button } from '@/components/ui/button';
import { PaymentReceipt } from '@/features/payments/payment-receipt';
import { type Payment } from '@/features/purchasing/types';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';

export default function ShowSupplierPayment({ payment: { data: payment } }: { payment: { data: Payment } }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Supplier payments', href: route('supplier-payments.index') },
                { title: payment.payment_no, href: route('supplier-payments.show', payment.id) },
            ]}
        >
            <Head title={payment.payment_no} />
            <div className="max-w-2xl space-y-6 p-4 md:p-6">
                <PaymentReceipt payment={payment} />
                <div className="flex flex-wrap gap-2">
                    <Button asChild>
                        <Link href={route('supplier-payments.print', payment.id)}>
                            <Printer className="size-4" /> Print voucher
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={route('supplier-payments.index')}>Back to payments</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
