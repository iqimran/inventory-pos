import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CustomerPicker } from '@/features/sales/customer-picker';
import { type Customer } from '@/features/sales/types';
import { DeviceFields, emptyDevice, type DeviceDraft } from '@/features/service/device-fields';
import { type Device, type Technician } from '@/features/service/types';
import AppLayout from '@/layouts/app-layout';
import { getJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface CreateJobProps {
    technicians: Technician[];
    customer: Customer | null;
    devices: Device[] | { data: Device[] };
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const textareaClass = 'border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm';

const unwrap = (devices: CreateJobProps['devices']) => (Array.isArray(devices) ? devices : devices.data);

export default function CreateServiceJob({ technicians, customer: initialCustomer, devices: initialDevices }: CreateJobProps) {
    const [customer, setCustomer] = useState<Customer | null>(initialCustomer);
    const [devices, setDevices] = useState<Device[]>(unwrap(initialDevices));
    const form = useForm({
        party_id: initialCustomer ? String(initialCustomer.id) : '',
        device_id: unwrap(initialDevices)[0] ? String(unwrap(initialDevices)[0].id) : '',
        device: emptyDevice as DeviceDraft,
        technician_id: '',
        complaint: '',
        estimated_amount: '',
        promised_at: '',
        notes: '',
    });
    const newDevice = form.data.device_id === '';

    const selectCustomer = async (picked: Customer | null) => {
        setCustomer(picked);
        setDevices([]);
        form.setData((data) => ({ ...data, party_id: picked ? String(picked.id) : '', device_id: '' }));

        if (picked) {
            const response = await getJson<{ data: Device[] }>(route('service.customer-devices', picked.id));
            setDevices(response.data);
            form.setData((data) => ({ ...data, device_id: response.data[0] ? String(response.data[0].id) : '' }));
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, device: newDevice ? data.device : null }));
        form.post(route('service-jobs.store'));
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Service jobs', href: route('service-jobs.index') },
                { title: 'New job', href: route('service-jobs.create') },
            ]}
        >
            <Head title="New service job" />
            <form onSubmit={submit} className="max-w-3xl space-y-8 p-4 md:p-6">
                <Heading title="New service job" description="Receive a device for repair." />

                <section className="space-y-3">
                    <h3 className="font-medium">
                        1. Customer
                        <span className="text-destructive ml-0.5" aria-hidden="true">
                            *
                        </span>
                    </h3>
                    <CustomerPicker value={customer} onChange={selectCustomer} placeholder="Search customer by name or phone" />
                    <InputError message={form.errors.party_id} />
                </section>

                {customer && (
                    <section className="space-y-3">
                        <h3 className="font-medium">
                            2. Device
                            <span className="text-destructive ml-0.5" aria-hidden="true">
                                *
                            </span>
                        </h3>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {devices.map((device) => (
                                <label
                                    key={device.id}
                                    className={cn(
                                        'flex cursor-pointer flex-col rounded-md border p-3 text-sm',
                                        form.data.device_id === String(device.id) && 'border-primary ring-primary ring-1',
                                    )}
                                >
                                    <span className="flex items-center gap-2 font-medium">
                                        <input
                                            type="radio"
                                            name="device_id"
                                            checked={form.data.device_id === String(device.id)}
                                            onChange={() => form.setData('device_id', String(device.id))}
                                        />
                                        {device.name}
                                    </span>
                                    <span className="text-muted-foreground font-mono text-xs">
                                        {device.imei1 ?? device.serial_no ?? 'No IMEI / serial'}
                                    </span>
                                </label>
                            ))}
                            <label
                                className={cn(
                                    'flex cursor-pointer items-center gap-2 rounded-md border p-3 text-sm',
                                    newDevice && 'border-primary ring-primary ring-1',
                                )}
                            >
                                <input type="radio" name="device_id" checked={newDevice} onChange={() => form.setData('device_id', '')} />
                                New device
                            </label>
                        </div>
                        <InputError message={form.errors.device_id} />
                        {newDevice && (
                            <div className="rounded-lg border p-4">
                                <DeviceFields
                                    value={form.data.device}
                                    onChange={(device) => form.setData('device', device)}
                                    errors={form.errors as Record<string, string>}
                                    errorPrefix="device."
                                />
                            </div>
                        )}
                    </section>
                )}

                <section className="space-y-4">
                    <h3 className="font-medium">3. Job</h3>
                    <div className="grid gap-2">
                        <Label htmlFor="complaint" required>
                            Customer complaint
                        </Label>
                        <textarea
                            id="complaint"
                            className={textareaClass}
                            value={form.data.complaint}
                            onChange={(e) => form.setData('complaint', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.complaint} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="technician_id">Technician</Label>
                            <select
                                id="technician_id"
                                className={selectClass}
                                value={form.data.technician_id}
                                onChange={(e) => form.setData('technician_id', e.target.value)}
                            >
                                <option value="">Unassigned</option>
                                {technicians.map((technician) => (
                                    <option key={technician.id} value={technician.id}>
                                        {technician.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.technician_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="estimated_amount">Estimate</Label>
                            <Input
                                id="estimated_amount"
                                type="number"
                                min={0}
                                step="0.01"
                                value={form.data.estimated_amount}
                                onChange={(e) => form.setData('estimated_amount', e.target.value)}
                            />
                            <InputError message={form.errors.estimated_amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="promised_at">Promised for</Label>
                            <Input
                                id="promised_at"
                                type="datetime-local"
                                value={form.data.promised_at}
                                onChange={(e) => form.setData('promised_at', e.target.value)}
                            />
                            <InputError message={form.errors.promised_at} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="notes">Notes</Label>
                        <Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </div>
                </section>

                <Button type="submit" disabled={form.processing || !customer}>
                    {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                    Open job
                </Button>
            </form>
        </AppLayout>
    );
}
