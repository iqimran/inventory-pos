import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CustomerPicker } from '@/features/sales/customer-picker';
import { type Customer } from '@/features/sales/types';
import { DeviceFields, emptyDevice, type DeviceDraft } from '@/features/service/device-fields';
import { type Device } from '@/features/service/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface DevicesIndexProps {
    devices: Paginated<Device>;
    filters: { q: string };
}

const toDraft = (device: Device): DeviceDraft => ({
    brand: device.brand,
    model: device.model,
    imei1: device.imei1 ?? '',
    imei2: device.imei2 ?? '',
    serial_no: device.serial_no ?? '',
    color: device.color ?? '',
    notes: device.notes ?? '',
});

export default function DevicesIndex({ devices, filters }: DevicesIndexProps) {
    const can = useCan();
    const [q, setQ] = useState(filters.q);
    const [editing, setEditing] = useState<Device | 'new' | null>(null);
    const [owner, setOwner] = useState<Customer | null>(null);
    const form = useForm<DeviceDraft & { party_id: string }>({ ...emptyDevice, party_id: '' });

    const open = (device: Device | 'new') => {
        form.clearErrors();
        setOwner(null);
        form.setData(device === 'new' ? { ...emptyDevice, party_id: '' } : { ...toDraft(device), party_id: '' });
        setEditing(device);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new') {
            form.post(route('devices.store'), options);
        } else if (editing) {
            // The owner is fixed once registered.
            form.transform((data) => ({
                brand: data.brand,
                model: data.model,
                imei1: data.imei1,
                imei2: data.imei2,
                serial_no: data.serial_no,
                color: data.color,
                notes: data.notes,
            }));
            form.put(route('devices.update', editing.id), options);
        }
    };

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('devices.index'), q ? { q } : {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Devices', href: route('devices.index') }]}>
            <Head title="Devices" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Devices" description="Customer handsets with IMEI and serial numbers." />
                    {can('service.manage') && <Button onClick={() => open('new')}>Register device</Button>}
                </div>

                <form onSubmit={search} className="flex gap-2">
                    <Input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="IMEI, serial, brand, model or customer phone"
                        className="sm:max-w-md"
                    />
                    <Button type="submit" variant="secondary">
                        Search
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Device</th>
                                <th className="px-4 py-3 font-medium">IMEI</th>
                                <th className="px-4 py-3 font-medium">Serial</th>
                                <th className="px-4 py-3 font-medium">Customer</th>
                                <th className="px-4 py-3 text-right font-medium">Jobs</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {devices.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No devices found.
                                    </td>
                                </tr>
                            )}
                            {devices.data.map((device) => (
                                <tr key={device.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{device.name}</div>
                                        {device.color && <div className="text-muted-foreground text-xs">{device.color}</div>}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">
                                        {device.imei1 ?? '—'}
                                        {device.imei2 && <div>{device.imei2}</div>}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">{device.serial_no ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        {device.party && (
                                            <Link href={route('parties.show', device.party.id)} className="hover:underline">
                                                {device.party.name}
                                            </Link>
                                        )}
                                        <div className="text-muted-foreground text-xs">{device.party?.phone}</div>
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{device.service_jobs_count ?? 0}</td>
                                    <td className="px-4 py-3 text-right whitespace-nowrap">
                                        {can('service.manage') && (
                                            <>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={route('service-jobs.create', { party_id: device.party_id })}>New job</Link>
                                                </Button>
                                                <Button variant="ghost" size="sm" onClick={() => open(device)}>
                                                    Edit
                                                </Button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination meta={devices.meta} />
            </div>

            <Dialog open={editing !== null} onOpenChange={(value) => !value && setEditing(null)}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogTitle>{editing === 'new' ? 'Register device' : 'Edit device'}</DialogTitle>
                    <DialogDescription>IMEIs are checked for a valid check digit. Dial *#06# on the phone to read them.</DialogDescription>
                    <form onSubmit={submit} className="space-y-4">
                        {editing === 'new' && (
                            <div className="grid gap-2">
                                <Label required>Customer</Label>
                                <CustomerPicker
                                    value={owner}
                                    onChange={(customer) => {
                                        setOwner(customer);
                                        form.setData('party_id', customer ? String(customer.id) : '');
                                    }}
                                    placeholder="Search customer by name or phone"
                                />
                                <InputError message={form.errors.party_id} />
                            </div>
                        )}
                        <DeviceFields
                            value={form.data}
                            onChange={(device) => form.setData((data) => ({ ...data, ...device }))}
                            errors={form.errors}
                        />
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Save device
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
