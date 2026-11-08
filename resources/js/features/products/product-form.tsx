import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Product, type ProductFormOptions } from '@/features/products/types';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, ReactNode } from 'react';

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

function Field({
    id,
    label,
    error,
    children,
    hint,
    required,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
    hint?: string;
    required?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id} required={required}>
                {label}
            </Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

export function ProductForm({ product, options }: { product?: Product; options: ProductFormOptions }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: product?.name ?? '',
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        description: product?.description ?? '',
        category_id: product?.category_id ? String(product.category_id) : '',
        subcategory_id: product?.subcategory_id ? String(product.subcategory_id) : '',
        brand_id: product?.brand_id ? String(product.brand_id) : '',
        unit_id: product?.unit_id ? String(product.unit_id) : '',
        purchase_price: product?.purchase_price ?? '0.00',
        retail_price: product?.retail_price ?? '0.00',
        wholesale_price: product?.wholesale_price ?? '0.00',
        reorder_level: String(product?.reorder_level ?? 0),
        is_active: product?.is_active ?? true,
        generate_barcode: false as boolean,
    });

    const subcategories = options.subcategories.filter((subcategory) => String(subcategory.category_id) === data.category_id);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (product) {
            put(route('products.update', product.id), { preserveScroll: true });
        } else {
            post(route('products.store'), { preserveScroll: true });
        }
    };

    return (
        <form onSubmit={submit} className="space-y-8">
            <section className="grid gap-6 md:grid-cols-2">
                <Field required id="name" label="Product name" error={errors.name}>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                </Field>
                <Field required id="sku" label="SKU" error={errors.sku} hint="Unique stock code. Stored in upper case.">
                    <Input id="sku" value={data.sku} onChange={(e) => setData('sku', e.target.value.toUpperCase())} required autoComplete="off" />
                </Field>
                <Field id="barcode" label="Barcode" error={errors.barcode} hint="Optional, must be unique. Scan it into this field.">
                    <Input id="barcode" value={data.barcode} onChange={(e) => setData('barcode', e.target.value)} autoComplete="off" />
                    {data.barcode.trim() === '' && (
                        <label className="mt-1 flex items-center gap-2 text-xs">
                            <input type="checkbox" checked={data.generate_barcode} onChange={(e) => setData('generate_barcode', e.target.checked)} />
                            Generate an internal Code 128 barcode on save
                        </label>
                    )}
                </Field>
                <Field required id="unit_id" label="Unit" error={errors.unit_id}>
                    <select id="unit_id" className={selectClass} value={data.unit_id} onChange={(e) => setData('unit_id', e.target.value)} required>
                        <option value="">Select unit…</option>
                        {options.units.map((unit) => (
                            <option key={unit.id} value={unit.id}>
                                {unit.name} ({unit.short_name})
                            </option>
                        ))}
                    </select>
                </Field>
                <Field required id="category_id" label="Category" error={errors.category_id}>
                    <select
                        id="category_id"
                        className={selectClass}
                        value={data.category_id}
                        onChange={(e) => setData((current) => ({ ...current, category_id: e.target.value, subcategory_id: '' }))}
                        required
                    >
                        <option value="">Select category…</option>
                        {options.categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field id="subcategory_id" label="Subcategory" error={errors.subcategory_id}>
                    <select
                        id="subcategory_id"
                        className={selectClass}
                        value={data.subcategory_id}
                        onChange={(e) => setData('subcategory_id', e.target.value)}
                        disabled={subcategories.length === 0}
                    >
                        <option value="">None</option>
                        {subcategories.map((subcategory) => (
                            <option key={subcategory.id} value={subcategory.id}>
                                {subcategory.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field id="brand_id" label="Brand" error={errors.brand_id}>
                    <select id="brand_id" className={selectClass} value={data.brand_id} onChange={(e) => setData('brand_id', e.target.value)}>
                        <option value="">None</option>
                        {options.brands.map((brand) => (
                            <option key={brand.id} value={brand.id}>
                                {brand.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field
                    id="reorder_level"
                    required
                    label="Reorder level"
                    error={errors.reorder_level}
                    hint="Flag as low stock at or below this quantity. 0 disables."
                >
                    <Input
                        id="reorder_level"
                        type="number"
                        min={0}
                        step={1}
                        value={data.reorder_level}
                        onChange={(e) => setData('reorder_level', e.target.value)}
                        required
                    />
                </Field>
            </section>

            <section className="grid gap-6 md:grid-cols-3">
                {(['purchase_price', 'retail_price', 'wholesale_price'] as const).map((field) => (
                    <Field
                        key={field}
                        required
                        id={field}
                        label={field.replace('_', ' ').replace(/^\w/, (c) => c.toUpperCase())}
                        error={errors[field]}
                    >
                        <Input
                            id={field}
                            type="number"
                            inputMode="decimal"
                            min={0}
                            step="0.01"
                            value={data[field]}
                            onChange={(e) => setData(field, e.target.value)}
                            required
                        />
                    </Field>
                ))}
            </section>

            <Field id="description" label="Description" error={errors.description}>
                <textarea
                    id="description"
                    rows={3}
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                />
            </Field>

            <div className="flex items-center gap-2">
                <Checkbox id="is_active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked === true)} />
                <Label htmlFor="is_active">Active (available for sale)</Label>
            </div>

            {!product && (
                <p className="text-muted-foreground text-sm">
                    New products start with zero stock. Record opening stock with a stock adjustment after saving.
                </p>
            )}

            <div className="flex items-center gap-2">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    {product ? 'Save changes' : 'Create product'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href={product ? route('products.show', product.id) : route('products.index')}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
