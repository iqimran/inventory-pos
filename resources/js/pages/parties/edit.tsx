import Heading from '@/components/heading';
import { PartyForm } from '@/features/parties/party-form';
import { type Party, type SelectOption } from '@/features/purchasing/types';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

interface EditPartyProps {
    party: { data: Party };
    types: SelectOption[];
    openingBalanceTypes: SelectOption[];
}

export default function EditParty({ party: { data: party }, ...options }: EditPartyProps) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Parties', href: route('parties.index') },
                { title: party.name, href: route('parties.show', party.id) },
                { title: 'Edit', href: route('parties.edit', party.id) },
            ]}
        >
            <Head title={`Edit ${party.name}`} />
            <div className="p-4 md:p-6">
                <Heading title={`Edit ${party.name}`} />
                <PartyForm party={party} {...options} />
            </div>
        </AppLayout>
    );
}
