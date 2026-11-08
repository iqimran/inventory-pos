import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Product, type ProductFormOptions } from '@/features/products/types';
import { getJson, HttpError, postJson } from '@/lib/http';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const emptyDraft = {
    name: '',
    sku: '',
    barcode: '',
    category_id: '',
    subcategory_id: '',
    brand_id: '',
    unit_id: '',
    purchase_price: '0.00',
    retail_price: '0.00',
    wholesale_price: '0.00',
    reorder_level: '0',
    generate_barcode: false as boolean,
};

type Draft = typeof emptyDraft;

interface QuickProductDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Search text to start from: digits prefill the barcode, anything else the name. */
    initialTerm?: string;
    onCreated: (product: Product) => void;
}

/**
 * Creates a product in a dialog (same validation as the product screen) and hands it back,
 * so a purchase can continue without leaving the page.
 */
export function QuickProductDialog({ open, onOpenChange, initialTerm = '', onCreated }: QuickProductDialogProps) {
    const [options, setOptions] = useState<ProductFormOptions | null>(null);
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        const term = initialTerm.trim();
        setDraft({ ...emptyDraft, ...(/^\d{6,}$/.test(term) ? { barcode: term } : { name: term }) });
        setErrors({});
    }, [open, initialTerm]);

    useEffect(() => {
        if (open && !options) {
            getJson<{ data: ProductFormOptions }>(route('products.quick.options'))
                .then((response) => setOptions(response.data))
                .catch(() => setErrors({ name: 'Could not load categories and units. Please try again.' }));
        }
    }, [open, options]);

    const set = <K extends keyof Draft>(field: K, value: Draft[K]) => setDraft((current) => ({ ...current, [field]: value }));

    const subcategories = options?.subcategories.filter((subcategory) => String(subcategory.category_id) === draft.category_id) ?? [];

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        try {
            const response = await postJson<{ data: Product }>(route('products.quick.store'), { ...draft, is_active: true });
            onCreated(response.data);
            onOpenChange(false);
        } catch (error) {
            if (error instanceof HttpError && error.body.errors) {
                setErrors(Object.fromEntries(Object.entries(error.body.errors).map(([key, messages]) => [key, messages[0]])));
            } else if (error instanceof HttpError && error.status === 403) {
                setErrors({ name: 'You do not have permission to create products.' });
            } else {
                setErrors({ name: 'Could not save the product. Please try again.' });
            }
        } finally {
            setSaving(false);
        }
    };

    const field = (id: keyof Draft, label: string, input: React.ReactNode, required = false) => (
        <div className="grid gap-2">
            <Label htmlFor={`quick-${id}`} required={required}>
                {label}
            </Label>
            {input}
            <InputError message={errors[id]} />
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogTitle>New product</DialogTitle>
                <DialogDescription>Saved to the catalogue and added to this purchase. Stock comes from the purchase itself.</DialogDescription>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field(
                            'name',
                            'Product name',
                            <Input id="quick-name" value={draft.name} onChange={(e) => set('name', e.target.value)} required autoFocus />,
                            true,
                        )}
                        {field(
                            'sku',
                            'SKU',
                            <Input
                                id="quick-sku"
                                value={draft.sku}
                                onChange={(e) => set('sku', e.target.value.toUpperCase())}
                                required
                                autoComplete="off"
                            />,
                            true,
                        )}
                        {field(
                            'barcode',
                            'Barcode',
                            <>
                                <Input id="quick-barcode" value={draft.barcode} onChange={(e) => set('barcode', e.target.value)} autoComplete="off" />
                                {draft.barcode.trim() === '' && (
                                    <label className="flex items-center gap-2 text-xs">
                                        <input
                                            type="checkbox"
                                            checked={draft.generate_barcode}
                                            onChange={(e) => set('generate_barcode', e.target.checked)}
                                        />
                                        Generate an internal Code 128 barcode on save
                                    </label>
                                )}
                            </>,
                        )}
                        {field(
                            'unit_id',
                            'Unit',
                            <select
                                id="quick-unit_id"
                                className={selectClass}
                                value={draft.unit_id}
                                onChange={(e) => set('unit_id', e.target.value)}
                                required
                            >
                                <option value="">Select unit…</option>
                                {options?.units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.name} ({unit.short_name})
                                    </option>
                                ))}
                            </select>,
                            true,
                        )}
                        {field(
                            'category_id',
                            'Category',
                            <select
                                id="quick-category_id"
                                className={selectClass}
                                value={draft.category_id}
                                onChange={(e) => setDraft((current) => ({ ...current, category_id: e.target.value, subcategory_id: '' }))}
                                required
                            >
                                <option value="">Select category…</option>
                                {options?.categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </select>,
                            true,
                        )}
                        {field(
                            'subcategory_id',
                            'Subcategory',
                            <select
                                id="quick-subcategory_id"
                                className={selectClass}
                                value={draft.subcategory_id}
                                onChange={(e) => set('subcategory_id', e.target.value)}
                                disabled={subcategories.length === 0}
                            >
                                <option value="">None</option>
                                {subcategories.map((subcategory) => (
                                    <option key={subcategory.id} value={subcategory.id}>
                                        {subcategory.name}
                                    </option>
                                ))}
                            </select>,
                        )}
                        {field(
                            'brand_id',
                            'Brand',
                            <select
                                id="quick-brand_id"
                                className={selectClass}
                                value={draft.brand_id}
                                onChange={(e) => set('brand_id', e.target.value)}
                            >
                                <option value="">None</option>
                                {options?.brands.map((brand) => (
                                    <option key={brand.id} value={brand.id}>
                                        {brand.name}
                                    </option>
                                ))}
                            </select>,
                        )}
                        {field(
                            'reorder_level',
                            'Reorder level',
                            <Input
                                id="quick-reorder_level"
                                type="number"
                                min={0}
                                step={1}
                                value={draft.reorder_level}
                                onChange={(e) => set('reorder_level', e.target.value)}
                                required
                            />,
                            true,
                        )}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {(
                            [
                                ['purchase_price', 'Purchase price'],
                                ['retail_price', 'Retail price'],
                                ['wholesale_price', 'Wholesale price'],
                            ] as const
                        ).map(([name, label]) => (
                            <div key={name}>
                                {field(
                                    name,
                                    label,
                                    <Input
                                        id={`quick-${name}`}
                                        type="number"
                                        inputMode="decimal"
                                        min={0}
                                        step="0.01"
                                        value={draft[name]}
                                        onChange={(e) => set(name, e.target.value)}
                                        required
                                    />,
                                    true,
                                )}
                            </div>
                        ))}
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={saving || !options}>
                            {saving && <LoaderCircle className="size-4 animate-spin" />}
                            Save and add
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
