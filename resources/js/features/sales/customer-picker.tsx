import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PartyBalance } from '@/features/parties/balance';
import { type Customer } from '@/features/sales/types';
import { getJson, HttpError, postJson } from '@/lib/http';
import { Search, UserPlus, X } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

interface CustomerPickerProps {
    value: Customer | null;
    onChange: (customer: Customer | null) => void;
    allowCreate?: boolean;
    placeholder?: string;
}

/**
 * Search customers by name/phone, pick one, or quick-add a new one (reused by POS and due collection).
 */
export function CustomerPicker({
    value,
    onChange,
    allowCreate = true,
    placeholder = 'Walk-in customer — search name or phone',
}: CustomerPickerProps) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<Customer[]>([]);
    const [creating, setCreating] = useState(false);
    const [draft, setDraft] = useState({ name: '', phone: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const abort = useRef<AbortController | null>(null);

    useEffect(() => {
        if (term.trim().length < 2) {
            setResults([]);
            return;
        }

        const timer = window.setTimeout(() => {
            abort.current?.abort();
            abort.current = new AbortController();
            getJson<{ data: Customer[] }>(route('pos.customers', { q: term }), abort.current.signal)
                .then((response) => setResults(response.data))
                .catch(() => undefined);
        }, 200);

        return () => window.clearTimeout(timer);
    }, [term]);

    const choose = (customer: Customer) => {
        onChange(customer);
        setTerm('');
        setResults([]);
    };

    const create: FormEventHandler = async (e) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        try {
            const response = await postJson<{ data: Customer }>(route('pos.customers.store'), draft);
            choose(response.data);
            setCreating(false);
            setDraft({ name: '', phone: '' });
        } catch (error) {
            if (error instanceof HttpError && error.body.errors) {
                setErrors(Object.fromEntries(Object.entries(error.body.errors).map(([key, messages]) => [key, messages[0]])));
            } else {
                setErrors({ name: 'Could not save the customer. Please try again.' });
            }
        } finally {
            setSaving(false);
        }
    };

    if (value) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                <div className="min-w-0">
                    <div className="truncate font-medium">{value.name}</div>
                    <div className="text-muted-foreground text-xs">
                        {value.phone ?? 'No phone'} · <PartyBalance balance={value.balance} />
                    </div>
                </div>
                <Button variant="ghost" size="icon" onClick={() => onChange(null)} aria-label="Clear customer">
                    <X className="size-4" />
                </Button>
            </div>
        );
    }

    return (
        <div className="relative">
            <div className="flex gap-2">
                <div className="relative flex-1">
                    <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                    <Input
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder={placeholder}
                        className="pl-8"
                        aria-label="Customer search"
                    />
                </div>
                {allowCreate && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={() => {
                            setDraft({ name: /\d/.test(term) ? '' : term, phone: /\d/.test(term) ? term : '' });
                            setCreating(true);
                        }}
                        aria-label="New customer"
                    >
                        <UserPlus className="size-4" />
                    </Button>
                )}
            </div>
            {results.length > 0 && (
                <ul className="bg-background absolute z-20 mt-1 w-full divide-y rounded-md border shadow-lg">
                    {results.map((customer) => (
                        <li key={customer.id}>
                            <button
                                type="button"
                                onClick={() => choose(customer)}
                                className="hover:bg-muted flex w-full justify-between gap-2 px-3 py-2 text-left text-sm"
                            >
                                <span>
                                    <span className="font-medium">{customer.name}</span>
                                    <span className="text-muted-foreground ml-2 text-xs">{customer.phone}</span>
                                </span>
                                <PartyBalance balance={customer.balance} className="text-xs" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogTitle>New customer</DialogTitle>
                    <DialogDescription>Saved as a customer party so it can be reused for future sales and service.</DialogDescription>
                    <form onSubmit={create} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="customer-name">Name</Label>
                            <Input
                                id="customer-name"
                                value={draft.name}
                                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                                required
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="customer-phone">Phone</Label>
                            <Input
                                id="customer-phone"
                                inputMode="tel"
                                value={draft.phone}
                                onChange={(e) => setDraft({ ...draft, phone: e.target.value })}
                                required
                            />
                            <InputError message={errors.phone} />
                        </div>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={saving}>
                                Save customer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
