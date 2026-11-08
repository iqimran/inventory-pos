import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Party, type SelectOption } from '@/features/purchasing/types';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

interface PartyFormProps {
    party?: Party;
    types: SelectOption[];
    openingBalanceTypes: SelectOption[];
}

export function PartyForm({ party, types, openingBalanceTypes }: PartyFormProps) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: party?.name ?? '',
        type: party?.type ?? 'SUPPLIER',
        phone: party?.phone ?? '',
        email: party?.email ?? '',
        address: party?.address ?? '',
        notes: party?.notes ?? '',
        is_active: party?.is_active ?? true,
        opening_balance: '0.00',
        opening_balance_type: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (party) {
            put(route('parties.update', party.id));
        } else {
            post(route('parties.store'));
        }
    };

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-6">
            <div className="grid gap-6 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="name" required>
                        Name
                    </Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="type" required>
                        Type
                    </Label>
                    <select id="type" className={selectClass} value={data.type} onChange={(e) => setData('type', e.target.value)}>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.type} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="phone">Phone</Label>
                    <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} inputMode="tel" />
                    <InputError message={errors.phone} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                    <InputError message={errors.email} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="address">Address</Label>
                <Input id="address" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                <InputError message={errors.address} />
            </div>

            {party ? (
                <p className="text-muted-foreground rounded-lg border p-3 text-sm">
                    The opening balance was posted to the ledger when this party was created. Use a ledger adjustment to correct balances.
                </p>
            ) : (
                <div className="grid gap-6 rounded-lg border p-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="opening_balance">Opening balance</Label>
                        <Input
                            id="opening_balance"
                            type="number"
                            min={0}
                            step="0.01"
                            inputMode="decimal"
                            value={data.opening_balance}
                            onChange={(e) => setData('opening_balance', e.target.value)}
                        />
                        <InputError message={errors.opening_balance} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="opening_balance_type">Opening balance is</Label>
                        <select
                            id="opening_balance_type"
                            className={selectClass}
                            value={data.opening_balance_type}
                            onChange={(e) => setData('opening_balance_type', e.target.value)}
                        >
                            <option value="">Select…</option>
                            {openingBalanceTypes.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.opening_balance_type} />
                    </div>
                </div>
            )}

            <div className="grid gap-2">
                <Label htmlFor="notes">Notes</Label>
                <textarea
                    id="notes"
                    rows={2}
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                />
                <InputError message={errors.notes} />
            </div>

            <div className="flex items-center gap-2">
                <Checkbox id="is_active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked === true)} />
                <Label htmlFor="is_active">Active</Label>
            </div>

            <div className="flex gap-2">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    {party ? 'Save changes' : 'Create party'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href={party ? route('parties.show', party.id) : route('parties.index')}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
