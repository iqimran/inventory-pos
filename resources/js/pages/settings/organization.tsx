import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { ImageUp, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

interface Organization {
    name: string;
    address: string | null;
    phone: string | null;
    receipt_footer: string | null;
    logo_url: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Organization settings', href: '/settings/organization' }];

export default function OrganizationSettings({ organization }: { organization: Organization }) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const { data, setData, post, errors, processing, recentlySuccessful, reset } = useForm({
        _method: 'put',
        name: organization.name,
        address: organization.address ?? '',
        phone: organization.phone ?? '',
        receipt_footer: organization.receipt_footer ?? '',
        logo: null as File | null,
        remove_logo: false as boolean,
    });

    // Local preview of a chosen (not yet saved) file.
    useEffect(() => {
        if (!data.logo) {
            setPreview(null);
            return;
        }

        const url = URL.createObjectURL(data.logo);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [data.logo]);

    const logo = preview ?? (data.remove_logo ? null : organization.logo_url);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // Multipart POST spoofed as PUT: browsers cannot send files with a real PUT form.
        post(route('organization.update'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset('logo', 'remove_logo');
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Organization settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Organization"
                        description="Shown in the sidebar, on the login page and browser tab, and at the top of printed invoices."
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="logo">Logo</Label>
                            <div className="flex items-center gap-4">
                                <div className="bg-muted/40 flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                                    {logo ? (
                                        <img src={logo} alt="Organization logo" className="size-full object-contain" />
                                    ) : (
                                        <ImageUp className="text-muted-foreground size-6" />
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <input
                                        ref={fileInput}
                                        id="logo"
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        className="block text-sm file:mr-3 file:rounded-md file:border file:bg-transparent file:px-3 file:py-1.5 file:text-sm"
                                        onChange={(e) => {
                                            setData((current) => ({ ...current, logo: e.target.files?.[0] ?? null, remove_logo: false }));
                                        }}
                                    />
                                    {(organization.logo_url || data.logo) && !data.remove_logo && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive"
                                            onClick={() => {
                                                setData((current) => ({ ...current, logo: null, remove_logo: Boolean(organization.logo_url) }));
                                                if (fileInput.current) fileInput.current.value = '';
                                            }}
                                        >
                                            <Trash2 className="size-4" /> Remove logo
                                        </Button>
                                    )}
                                    {data.remove_logo && <p className="text-muted-foreground text-xs">The logo will be removed when you save.</p>}
                                    <p className="text-muted-foreground text-xs">
                                        PNG, JPG or WebP, up to 2 MB. A square image works best for the sidebar and tab icon.
                                    </p>
                                </div>
                            </div>
                            <InputError message={errors.logo} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Organization name</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required placeholder="Shop name" />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="address">Address</Label>
                            <textarea
                                id="address"
                                className="border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                                placeholder="Street, area, city"
                            />
                            <InputError message={errors.address} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="phone">Contact number</Label>
                            <Input
                                id="phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="01700-000000, 01800-000000"
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="receipt_footer">Invoice footer</Label>
                            <Input
                                id="receipt_footer"
                                value={data.receipt_footer}
                                onChange={(e) => setData('receipt_footer', e.target.value)}
                                placeholder="Thank you for shopping with us."
                            />
                            <InputError message={errors.receipt_footer} />
                        </div>

                        <div className="rounded-md border p-4 text-center font-mono text-xs" aria-label="Invoice header preview">
                            {logo && <img src={logo} alt="" className="mx-auto mb-1 max-h-14 max-w-40 object-contain" />}
                            <div className="text-sm font-bold">{data.name || 'Organization name'}</div>
                            {data.address && <div className="whitespace-pre-line">{data.address}</div>}
                            {data.phone && <div>Tel: {data.phone}</div>}
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>
                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
