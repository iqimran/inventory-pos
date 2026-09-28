import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PaymentStatusBadge } from '@/features/purchasing/status-badge';
import { type SelectOption } from '@/features/purchasing/types';
import { type PosProduct } from '@/features/sales/types';
import { ServiceStatusBadge } from '@/features/service/status-badge';
import { type ServiceJob, type ServiceJobPart, type ServiceJobStatus, type Technician } from '@/features/service/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney, fromCents, toCents } from '@/lib/format';
import { getJson } from '@/lib/http';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Lock, Printer, Search, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

interface ShowJobProps {
    job: { data: ServiceJob };
    technicians: Technician[];
    methods: SelectOption[];
    canOverridePrice: boolean;
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const textareaClass = 'border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm';
const toLocalInput = (iso: string | null) =>
    iso ? new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '';

export default function ShowServiceJob({ job: { data: job }, technicians, methods, canOverridePrice }: ShowJobProps) {
    const can = useCan();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const manage = can('service.manage');
    const open = !['DELIVERED', 'CANCELLED'].includes(job.status);
    const billable = manage && open && !job.invoice;
    const draftTotal = fromCents(toCents(job.parts_total) + toCents(job.service_charge));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Service jobs', href: route('service-jobs.index') },
                { title: job.job_no, href: route('service-jobs.show', job.id) },
            ]}
        >
            <Head title={job.job_no} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-1">
                        <h2 className="flex flex-wrap items-center gap-2 font-mono text-xl font-semibold">
                            {job.job_no}
                            <ServiceStatusBadge status={job.status} label={job.status_label} />
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Received {formatDateTime(job.received_at)}
                            {job.created_by && ` by ${job.created_by}`}
                            {job.promised_at && ` · Promised ${formatDateTime(job.promised_at)}`}
                        </p>
                    </div>
                    {manage && <StatusActions job={job} />}
                </div>

                {(errors.job || errors.status) && (
                    <p className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-3 py-2 text-sm">
                        {errors.job ?? errors.status}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="min-w-0 space-y-6">
                        <JobDetails job={job} technicians={technicians} editable={manage && open} />
                        <PartsSection job={job} editable={billable} canOverridePrice={canOverridePrice} />
                        <ChargesSection job={job} editable={billable} />
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardContent className="space-y-2 pt-6 text-sm">
                                <h3 className="font-medium">Customer & device</h3>
                                {job.party && (
                                    <p>
                                        <Link href={route('parties.show', job.party.id)} className="font-medium hover:underline">
                                            {job.party.name}
                                        </Link>
                                        <span className="text-muted-foreground"> · {job.party.phone}</span>
                                    </p>
                                )}
                                {job.device && (
                                    <dl className="text-muted-foreground space-y-0.5 text-xs">
                                        <div className="text-foreground text-sm font-medium">{job.device.name}</div>
                                        {job.device.imei1 && <div className="font-mono">IMEI 1 {job.device.imei1}</div>}
                                        {job.device.imei2 && <div className="font-mono">IMEI 2 {job.device.imei2}</div>}
                                        {job.device.serial_no && <div className="font-mono">S/N {job.device.serial_no}</div>}
                                        {job.device.color && <div>{job.device.color}</div>}
                                        {job.device.notes && <div>{job.device.notes}</div>}
                                    </dl>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardContent className="space-y-1 pt-6 text-sm">
                                <h3 className="mb-2 font-medium">Bill</h3>
                                <Row label="Estimate" value={formatMoney(job.estimated_amount)} />
                                {job.approved_amount && <Row label="Approved" value={formatMoney(job.approved_amount)} />}
                                <Row label="Parts (product)" value={formatMoney(job.parts_total)} />
                                <Row label="Service charge" value={formatMoney(job.service_charge)} />
                                <Row label={job.invoice ? 'Invoiced' : 'Draft total'} value={formatMoney(job.invoice?.total ?? draftTotal)} strong />
                                {job.invoice && (
                                    <div className="space-y-2 border-t pt-2">
                                        <div className="flex items-center justify-between">
                                            <Link
                                                href={route('service-invoices.show', job.invoice.id)}
                                                className="font-mono whitespace-nowrap hover:underline"
                                            >
                                                {job.invoice.invoice_no}
                                            </Link>
                                            <PaymentStatusBadge status={job.invoice.payment_status} label={job.invoice.payment_status_label} />
                                        </div>
                                        <Row label="Due" value={formatMoney(job.invoice.due_amount)} strong />
                                        <Button size="sm" className="w-full" asChild>
                                            <Link href={route('service-invoices.print', job.invoice.id)}>
                                                <Printer className="size-4" /> Print invoice
                                            </Link>
                                        </Button>
                                        {can('sales.collect') && toCents(job.invoice.due_amount) > 0 && job.party && (
                                            <Button variant="secondary" size="sm" className="w-full" asChild>
                                                <Link
                                                    href={route('customer-payments.create', {
                                                        party_id: job.party.id,
                                                        service_invoice_id: job.invoice.id,
                                                    })}
                                                >
                                                    Collect due
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {manage && job.status === 'READY' && !job.invoice && <InvoiceForm job={job} methods={methods} draftTotal={draftTotal} />}
                        {!job.invoice && job.status !== 'READY' && open && (
                            <p className="text-muted-foreground text-xs">
                                Parts are a draft and do not affect stock. They are taken from stock when the job is Ready and invoiced.
                            </p>
                        )}

                        <StatusHistory job={job} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={strong ? 'flex justify-between border-t pt-1 font-semibold' : 'flex justify-between'}>
            <span>{label}</span>
            <span className="tabular-nums">{value}</span>
        </div>
    );
}

function StatusActions({ job }: { job: ServiceJob }) {
    const [target, setTarget] = useState<{ value: ServiceJobStatus; label: string } | null>(null);
    const form = useForm({ status: '', notes: '', diagnosis: '', estimated_amount: '', approved_amount: '', reason: '' });

    // An invoiced job can only be delivered; delivery needs an invoice.
    const options = job.next_statuses.filter((status) => (job.invoice ? status.value === 'DELIVERED' : status.value !== 'DELIVERED'));

    const choose = (status: { value: ServiceJobStatus; label: string }) => {
        form.clearErrors();
        form.setData({
            status: status.value,
            notes: '',
            diagnosis: job.diagnosis ?? '',
            estimated_amount: job.estimated_amount,
            approved_amount: job.estimated_amount,
            reason: '',
        });
        setTarget(status);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('service-jobs.status', job.id), { preserveScroll: true, onSuccess: () => setTarget(null) });
    };

    if (options.length === 0) return null;

    const approving = target?.value === 'IN_PROGRESS' && job.status === 'WAITING_FOR_APPROVAL';

    return (
        <>
            <div className="flex flex-wrap gap-2">
                {options.map((status) => (
                    <Button
                        key={status.value}
                        variant={status.value === 'CANCELLED' ? 'outline' : 'default'}
                        className={status.value === 'CANCELLED' ? 'text-destructive' : undefined}
                        onClick={() => choose(status)}
                    >
                        {status.value === 'CANCELLED' ? 'Cancel job' : `Move to ${status.label}`}
                    </Button>
                ))}
            </div>

            <Dialog open={target !== null} onOpenChange={(value) => !value && setTarget(null)}>
                <DialogContent>
                    <DialogTitle>{target?.value === 'CANCELLED' ? 'Cancel job' : `Move to ${target?.label}`}</DialogTitle>
                    <DialogDescription>
                        {target?.value === 'CANCELLED'
                            ? 'The job is kept for the record. Draft parts are released; nothing is taken from stock.'
                            : `${job.job_no}: ${job.status_label} → ${target?.label}`}
                    </DialogDescription>
                    <form onSubmit={submit} className="space-y-4">
                        {target?.value === 'WAITING_FOR_APPROVAL' && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="diagnosis">Diagnosis</Label>
                                    <textarea
                                        id="diagnosis"
                                        className={textareaClass}
                                        value={form.data.diagnosis}
                                        onChange={(e) => form.setData('diagnosis', e.target.value)}
                                        required
                                    />
                                    <InputError message={form.errors.diagnosis} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="estimate">Estimate for the customer</Label>
                                    <Input
                                        id="estimate"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={form.data.estimated_amount}
                                        onChange={(e) => form.setData('estimated_amount', e.target.value)}
                                    />
                                    <InputError message={form.errors.estimated_amount} />
                                </div>
                            </>
                        )}
                        {approving && (
                            <div className="grid gap-2">
                                <Label htmlFor="approved_amount">Amount approved by the customer</Label>
                                <Input
                                    id="approved_amount"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.approved_amount}
                                    onChange={(e) => form.setData('approved_amount', e.target.value)}
                                    required
                                />
                                <InputError message={form.errors.approved_amount} />
                            </div>
                        )}
                        {target?.value === 'CANCELLED' ? (
                            <div className="grid gap-2">
                                <Label htmlFor="reason">Reason</Label>
                                <Input id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                                <InputError message={form.errors.reason} />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="status-notes">Note (optional)</Label>
                                <Input id="status-notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                            </div>
                        )}
                        <InputError message={form.errors.status} />
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setTarget(null)}>
                                Back
                            </Button>
                            <Button type="submit" variant={target?.value === 'CANCELLED' ? 'destructive' : 'default'} disabled={form.processing}>
                                Confirm
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function JobDetails({ job, technicians, editable }: { job: ServiceJob; technicians: Technician[]; editable: boolean }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        technician_id: job.technician_id ? String(job.technician_id) : '',
        complaint: job.complaint,
        diagnosis: job.diagnosis ?? '',
        estimated_amount: job.estimated_amount,
        promised_at: toLocalInput(job.promised_at),
        notes: job.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('service-jobs.update', job.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    if (!editing) {
        return (
            <section className="rounded-lg border p-4">
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="font-medium">Job details</h3>
                    {editable && (
                        <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                            Edit
                        </Button>
                    )}
                </div>
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground text-xs">Complaint</dt>
                        <dd className="whitespace-pre-line">{job.complaint}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-xs">Diagnosis</dt>
                        <dd className="whitespace-pre-line">{job.diagnosis ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-xs">Technician</dt>
                        <dd>{job.technician ?? 'Unassigned'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-xs">Notes</dt>
                        <dd>{job.notes ?? '—'}</dd>
                    </div>
                    {job.cancel_reason && (
                        <div className="sm:col-span-2">
                            <dt className="text-muted-foreground text-xs">Cancelled</dt>
                            <dd>
                                {formatDateTime(job.cancelled_at)} — {job.cancel_reason}
                            </dd>
                        </div>
                    )}
                </dl>
            </section>
        );
    }

    return (
        <form onSubmit={submit} className="space-y-4 rounded-lg border p-4">
            <h3 className="font-medium">Edit job details</h3>
            <div className="grid gap-4 sm:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="technician">Technician</Label>
                    <select
                        id="technician"
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
                    <Label htmlFor="estimate-edit">Estimate</Label>
                    <Input
                        id="estimate-edit"
                        type="number"
                        min={0}
                        step="0.01"
                        value={form.data.estimated_amount}
                        onChange={(e) => form.setData('estimated_amount', e.target.value)}
                    />
                    <InputError message={form.errors.estimated_amount} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="promised">Promised for</Label>
                    <Input
                        id="promised"
                        type="datetime-local"
                        value={form.data.promised_at}
                        onChange={(e) => form.setData('promised_at', e.target.value)}
                    />
                </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="complaint-edit">Complaint</Label>
                    <textarea
                        id="complaint-edit"
                        className={textareaClass}
                        value={form.data.complaint}
                        onChange={(e) => form.setData('complaint', e.target.value)}
                    />
                    <InputError message={form.errors.complaint} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="diagnosis-edit">Diagnosis</Label>
                    <textarea
                        id="diagnosis-edit"
                        className={textareaClass}
                        value={form.data.diagnosis}
                        onChange={(e) => form.setData('diagnosis', e.target.value)}
                    />
                </div>
            </div>
            <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Notes" aria-label="Notes" />
            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                    Save
                </Button>
                <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function PartsSection({ job, editable, canOverridePrice }: { job: ServiceJob; editable: boolean; canOverridePrice: boolean }) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<PosProduct[]>([]);
    const abort = useRef<AbortController | null>(null);
    const { errors } = usePage().props as { errors: Record<string, string> };

    useEffect(() => {
        if (term.trim().length < 2) {
            setResults([]);
            return;
        }

        const timer = window.setTimeout(() => {
            abort.current?.abort();
            abort.current = new AbortController();
            getJson<{ data: PosProduct[] }>(route('pos.products', { q: term }), abort.current.signal)
                .then((response) => setResults(response.data))
                .catch(() => undefined);
        }, 250);

        return () => window.clearTimeout(timer);
    }, [term]);

    const add = (product: PosProduct) => {
        router.post(route('service-jobs.parts.store', job.id), { product_id: product.id, quantity: 1 }, { preserveScroll: true });
        setTerm('');
        setResults([]);
    };

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between">
                <h3 className="font-medium">Parts (product lines)</h3>
                {job.invoice && (
                    <span className="text-muted-foreground flex items-center gap-1 text-xs">
                        <Lock className="size-3" /> Consumed from stock on {job.invoice.invoice_no}
                    </span>
                )}
            </div>
            {editable && (
                <div className="relative">
                    <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                    <Input
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder="Add a part — search product, SKU or barcode"
                        className="pl-8"
                    />
                    {results.length > 0 && (
                        <ul className="bg-background absolute z-20 mt-1 max-h-72 w-full divide-y overflow-auto rounded-md border shadow-lg">
                            {results.map((product) => (
                                <li key={product.id}>
                                    <button
                                        type="button"
                                        onClick={() => add(product)}
                                        className="hover:bg-muted flex w-full justify-between gap-3 px-3 py-2 text-left text-sm"
                                    >
                                        <span>
                                            <span className="font-medium">{product.name}</span>
                                            <span className="text-muted-foreground ml-2 font-mono text-xs">{product.sku}</span>
                                        </span>
                                        <span className="text-right">
                                            <span className="block tabular-nums">{formatMoney(product.retail_price)}</span>
                                            <span className={product.stock <= 0 ? 'text-destructive text-xs' : 'text-muted-foreground text-xs'}>
                                                Stock {product.stock}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <InputError message={errors.product_id ?? errors.quantity ?? errors.unit_price} />
                </div>
            )}
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">Part</th>
                            <th className="w-24 px-3 py-2 font-medium">Qty</th>
                            <th className="w-32 px-3 py-2 font-medium">Price</th>
                            <th className="px-3 py-2 text-right font-medium">Total</th>
                            {editable && <th className="w-10 px-3 py-2" />}
                        </tr>
                    </thead>
                    <tbody>
                        {job.parts?.length === 0 && (
                            <tr>
                                <td colSpan={5} className="text-muted-foreground px-3 py-6 text-center">
                                    No parts.
                                </td>
                            </tr>
                        )}
                        {job.parts?.map((part) => (
                            <PartRow key={part.id} job={job} part={part} editable={editable} canOverridePrice={canOverridePrice} />
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function PartRow({ job, part, editable, canOverridePrice }: { job: ServiceJob; part: ServiceJobPart; editable: boolean; canOverridePrice: boolean }) {
    const [quantity, setQuantity] = useState(String(part.quantity));
    const [price, setPrice] = useState(part.unit_price);

    const save = () => {
        if (quantity === String(part.quantity) && toCents(price) === toCents(part.unit_price)) return;

        router.put(
            route('service-jobs.parts.update', [job.id, part.id]),
            { quantity, unit_price: canOverridePrice ? price : null },
            { preserveScroll: true, onError: () => (setQuantity(String(part.quantity)), setPrice(part.unit_price)) },
        );
    };

    return (
        <tr className="border-t align-top">
            <td className="px-3 py-2">
                <div className="font-medium">{part.product?.name}</div>
                <div className="text-muted-foreground text-xs">
                    <span className="font-mono">{part.product?.sku}</span>
                    {!part.consumed_at && part.stock !== null && ` · stock ${part.stock}`}
                    {!part.consumed_at && part.stock !== null && part.quantity > part.stock && (
                        <span className="text-destructive"> · exceeds stock</span>
                    )}
                    {part.consumed_at && ` · consumed ${formatDateTime(part.consumed_at)}`}
                </div>
            </td>
            <td className="px-3 py-2">
                {editable ? (
                    <Input
                        type="number"
                        min={1}
                        value={quantity}
                        onChange={(e) => setQuantity(e.target.value)}
                        onBlur={save}
                        className="h-8"
                        aria-label="Quantity"
                    />
                ) : (
                    part.quantity
                )}
            </td>
            <td className="px-3 py-2 tabular-nums">
                {editable && canOverridePrice ? (
                    <Input
                        type="number"
                        min={0}
                        step="0.01"
                        value={price}
                        onChange={(e) => setPrice(e.target.value)}
                        onBlur={save}
                        className="h-8"
                        aria-label="Price"
                    />
                ) : (
                    formatMoney(part.unit_price)
                )}
                {part.price_overridden && <div className="text-muted-foreground text-xs line-through">{formatMoney(part.list_price)}</div>}
            </td>
            <td className="px-3 py-2 text-right font-medium tabular-nums">{formatMoney(part.line_total)}</td>
            {editable && (
                <td className="px-3 py-2">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        onClick={() => router.delete(route('service-jobs.parts.destroy', [job.id, part.id]), { preserveScroll: true })}
                        aria-label={`Remove ${part.product?.name}`}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </td>
            )}
        </tr>
    );
}

function ChargesSection({ job, editable }: { job: ServiceJob; editable: boolean }) {
    const form = useForm({ description: 'Repair / labour', amount: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('service-jobs.charges.store', job.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <section className="space-y-3">
            <h3 className="font-medium">Service charges (service lines)</h3>
            <div className="rounded-lg border">
                {job.charges?.length === 0 && <p className="text-muted-foreground px-3 py-4 text-center text-sm">No service charge.</p>}
                <ul className="divide-y text-sm">
                    {job.charges?.map((charge) => (
                        <li key={charge.id} className="flex items-center justify-between gap-2 px-3 py-2">
                            <span>{charge.description}</span>
                            <span className="flex items-center gap-2">
                                <span className="tabular-nums">{formatMoney(charge.amount)}</span>
                                {editable && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        onClick={() =>
                                            router.delete(route('service-jobs.charges.destroy', [job.id, charge.id]), { preserveScroll: true })
                                        }
                                        aria-label={`Remove ${charge.description}`}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
            {editable && (
                <form onSubmit={submit} className="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div className="flex-1">
                        <Input
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            aria-label="Charge description"
                        />
                        <InputError message={form.errors.description} />
                    </div>
                    <div className="sm:w-36">
                        <Input
                            type="number"
                            min="0.01"
                            step="0.01"
                            value={form.data.amount}
                            onChange={(e) => form.setData('amount', e.target.value)}
                            placeholder="Amount"
                            aria-label="Charge amount"
                            required
                        />
                        <InputError message={form.errors.amount} />
                    </div>
                    <Button type="submit" variant="secondary" disabled={form.processing}>
                        Add charge
                    </Button>
                </form>
            )}
        </section>
    );
}

function InvoiceForm({ job, methods, draftTotal }: { job: ServiceJob; methods: SelectOption[]; draftTotal: string }) {
    const form = useForm({ discount: '0', paid_amount: '', payment_method: 'CASH', notes: '', deliver: true as boolean });
    const { errors } = usePage().props as { errors: Record<string, string> };
    const totalCents = Math.max(toCents(draftTotal) - toCents(form.data.discount), 0);
    const dueCents = Math.max(totalCents - toCents(form.data.paid_amount), 0);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, paid_amount: data.paid_amount === '' ? '0' : data.paid_amount }));
        form.post(route('service-jobs.invoice.store', job.id));
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-lg border p-4">
            <h3 className="font-medium">Invoice & payment</h3>
            <p className="text-muted-foreground text-xs">Invoicing takes the parts out of stock and posts the bill to the customer's ledger.</p>
            <div className="grid grid-cols-2 gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="discount">Discount</Label>
                    <Input
                        id="discount"
                        type="number"
                        min={0}
                        step="0.01"
                        value={form.data.discount}
                        onChange={(e) => form.setData('discount', e.target.value)}
                    />
                    <InputError message={form.errors.discount} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="paid_amount">Paid now</Label>
                    <Input
                        id="paid_amount"
                        type="number"
                        min={0}
                        step="0.01"
                        value={form.data.paid_amount}
                        onChange={(e) => form.setData('paid_amount', e.target.value)}
                        placeholder="0.00"
                    />
                    <button
                        type="button"
                        className="text-muted-foreground text-left text-xs hover:underline"
                        onClick={() => form.setData('paid_amount', fromCents(totalCents))}
                    >
                        Full amount
                    </button>
                    <InputError message={form.errors.paid_amount} />
                </div>
            </div>
            <select
                className={selectClass}
                value={form.data.payment_method}
                onChange={(e) => form.setData('payment_method', e.target.value)}
                aria-label="Payment method"
            >
                {methods.map((method) => (
                    <option key={method.value} value={method.value}>
                        {method.label}
                    </option>
                ))}
            </select>
            <Input
                value={form.data.notes}
                onChange={(e) => form.setData('notes', e.target.value)}
                placeholder="Invoice note"
                aria-label="Invoice note"
            />
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.data.deliver} onChange={(e) => form.setData('deliver', e.target.checked)} />
                Device handed over (mark delivered)
            </label>
            <div className="space-y-1 border-t pt-2 text-sm">
                <Row label="Total" value={formatMoney(fromCents(totalCents))} strong />
                <Row label="Due" value={formatMoney(fromCents(dueCents))} />
            </div>
            <InputError message={errors.job ?? errors.quantity} />
            <Button type="submit" className="w-full" disabled={form.processing}>
                Create invoice
            </Button>
        </form>
    );
}

function StatusHistory({ job }: { job: ServiceJob }) {
    return (
        <div className="rounded-lg border">
            <h3 className="border-b px-4 py-2 text-sm font-medium">Status history</h3>
            <ol className="divide-y text-sm">
                {job.status_logs?.map((log) => (
                    <li key={log.id} className="px-4 py-2">
                        <div className="flex justify-between gap-2">
                            <span className="font-medium">{log.to_status_label}</span>
                            <span className="text-muted-foreground text-xs">{formatDateTime(log.created_at)}</span>
                        </div>
                        <div className="text-muted-foreground text-xs">
                            {log.created_by ?? 'System'}
                            {log.notes && ` — ${log.notes}`}
                        </div>
                    </li>
                ))}
            </ol>
        </div>
    );
}
