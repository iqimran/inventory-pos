import Heading from '@/components/heading';
import { PartyForm } from '@/features/parties/party-form';
import { type SelectOption } from '@/features/purchasing/types';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function CreateParty(props: { types: SelectOption[]; openingBalanceTypes: SelectOption[] }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Parties', href: route('parties.index') },
                { title: 'New party', href: route('parties.create') },
            ]}
        >
            <Head title="New party" />
            <div className="p-4 md:p-6">
                <Heading title="New party" description="A supplier, a customer, or both." />
                <PartyForm {...props} />
            </div>
        </AppLayout>
    );
}
