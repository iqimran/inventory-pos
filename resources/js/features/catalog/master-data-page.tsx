import { ConfirmDialog } from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type Option, type Paginator } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, Plus } from 'lucide-react';
import { FormEventHandler, ReactNode, useState } from 'react';

export interface MasterRecord {
    id: number;
    name: string;
    is_active: boolean;
    [key: string]: unknown;
}

export interface FieldDef {
    name: string;
    label: string;
    type: 'text' | 'textarea' | 'select';
    required?: boolean;
    options?: Option[];
}

export interface ColumnDef<T> {
    label: string;
    render: (record: T) => ReactNode;
}

interface MasterDataPageProps<T extends MasterRecord> {
    title: string;
    singular: string;
    description: string;
    /** Route name prefix, e.g. "categories" → categories.index / categories.store. */
    routeName: string;
    records: Paginator<T>;
    search: string;
    columns: ColumnDef<T>[];
    fields: FieldDef[];
    extraFilters?: ReactNode;
    extraQuery?: Record<string, unknown>;
}

type FormData = Record<string, string | boolean>;

export function MasterDataPage<T extends MasterRecord>({
    title,
    singular,
    description,
    routeName,
    records,
    search: initialSearch,
    columns,
    fields,
    extraFilters,
    extraQuery = {},
}: MasterDataPageProps<T>) {
    const can = useCan();
    const canManage = can('products.manage');
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };
    const [search, setSearch] = useState(initialSearch);
    const [editing, setEditing] = useState<T | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [deleting, setDeleting] = useState<T | null>(null);

    const emptyForm = (): FormData => ({
        ...Object.fromEntries(fields.map((field) => [field.name, ''])),
        is_active: true,
    });

    const form = useForm<FormData>(emptyForm());

    const openCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData(emptyForm());
        setDialogOpen(true);
    };

    const openEdit = (record: T) => {
        setEditing(record);
        form.clearErrors();
        form.setData({
            ...Object.fromEntries(fields.map((field) => [field.name, record[field.name] == null ? '' : String(record[field.name])])),
            is_active: record.is_active,
        });
        setDialogOpen(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setDialogOpen(false) };

        if (editing) {
            form.put(route(`${routeName}.update`, editing.id), options);
        } else {
            form.post(route(`${routeName}.store`), options);
        }
    };

    const applySearch: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route(`${routeName}.index`), { ...extraQuery, search: search || undefined }, { preserveState: true, replace: true });
    };

    const destroy = () => {
        if (!deleting) return;
        router.delete(route(`${routeName}.destroy`, deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) });
    };

    return (
        <AppLayout breadcrumbs={[{ title, href: route(`${routeName}.index`) }]}>
            <Head title={title} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title={title} description={description} />
                    {canManage && (
                        <Button onClick={openCreate}>
                            <Plus className="size-4" /> New {singular.toLowerCase()}
                        </Button>
                    )}
                </div>

                <form onSubmit={applySearch} className="flex flex-col gap-2 sm:flex-row">
                    <Input placeholder="Search by name" value={search} onChange={(e) => setSearch(e.target.value)} className="sm:max-w-xs" />
                    {extraFilters}
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <InputError message={pageErrors.record} />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Name</th>
                                {columns.map((column) => (
                                    <th key={column.label} className="px-4 py-3 font-medium">
                                        {column.label}
                                    </th>
                                ))}
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {records.data.length === 0 && (
                                <tr>
                                    <td colSpan={columns.length + 3} className="text-muted-foreground px-4 py-8 text-center">
                                        No {title.toLowerCase()} found.
                                    </td>
                                </tr>
                            )}
                            {records.data.map((record) => (
                                <tr key={record.id} className="border-t">
                                    <td className="px-4 py-3 font-medium">{record.name}</td>
                                    {columns.map((column) => (
                                        <td key={column.label} className="px-4 py-3">
                                            {column.render(record)}
                                        </td>
                                    ))}
                                    <td className="px-4 py-3">
                                        <Badge variant={record.is_active ? 'secondary' : 'outline'}>{record.is_active ? 'Active' : 'Inactive'}</Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        {canManage && (
                                            <div className="flex justify-end gap-2">
                                                <Button variant="outline" size="sm" onClick={() => openEdit(record)}>
                                                    Edit
                                                </Button>
                                                <Button variant="destructive" size="sm" onClick={() => setDeleting(record)}>
                                                    Delete
                                                </Button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={records} />
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
                    <DialogTitle>{editing ? `Edit ${singular.toLowerCase()}` : `New ${singular.toLowerCase()}`}</DialogTitle>
                    <DialogDescription className="sr-only">
                        {editing ? 'Update' : 'Create'} a {singular.toLowerCase()}.
                    </DialogDescription>
                    <form onSubmit={submit} className="space-y-4">
                        {fields.map((field) => (
                            <div key={field.name} className="grid gap-2">
                                <Label htmlFor={field.name}>{field.label}</Label>
                                {field.type === 'select' ? (
                                    <select
                                        id={field.name}
                                        value={String(form.data[field.name] ?? '')}
                                        onChange={(e) => form.setData(field.name, e.target.value)}
                                        required={field.required}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    >
                                        <option value="">Select…</option>
                                        {field.options?.map((option) => (
                                            <option key={option.id} value={option.id}>
                                                {option.name}
                                            </option>
                                        ))}
                                    </select>
                                ) : field.type === 'textarea' ? (
                                    <textarea
                                        id={field.name}
                                        value={String(form.data[field.name] ?? '')}
                                        onChange={(e) => form.setData(field.name, e.target.value)}
                                        rows={3}
                                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    />
                                ) : (
                                    <Input
                                        id={field.name}
                                        value={String(form.data[field.name] ?? '')}
                                        onChange={(e) => form.setData(field.name, e.target.value)}
                                        required={field.required}
                                        autoComplete="off"
                                    />
                                )}
                                <InputError message={form.errors[field.name]} />
                            </div>
                        ))}
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="is_active"
                                checked={form.data.is_active === true}
                                onCheckedChange={(checked) => form.setData('is_active', checked === true)}
                            />
                            <Label htmlFor="is_active">Active</Label>
                        </div>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name}?`}
                description="Records that are in use cannot be deleted; deactivate them instead."
                confirmLabel="Delete"
                destructive
                onConfirm={destroy}
            />
        </AppLayout>
    );
}
