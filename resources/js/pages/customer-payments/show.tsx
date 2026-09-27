import { Button } from '@/components/ui/button';
import { PaymentReceipt } from '@/features/payments/payment-receipt';
import { type Payment } from '@/features/purchasing/types';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

export default function ShowCustomerPayment({ payment: { data: payment } }: { payment: { data: Payment } }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Customer payments', href: route('customer-payments.index') },
                { title: payment.payment_no, href: route('customer-payments.show', payment.id) },
            ]}
        >
            <Head title={payment.payment_no} />
            <div className="max-w-2xl space-y-6 p-4 md:p-6">
                <PaymentReceipt payment={payment} />
                <Button variant="outline" asChild>
                    <Link href={route('customer-payments.index')}>Back to customer payments</Link>
                </Button>
            </div>
        </AppLayout>
    );
}
