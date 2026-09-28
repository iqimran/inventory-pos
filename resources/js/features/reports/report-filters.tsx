import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router, usePage } from '@inertiajs/react';
import { FormEventHandler, ReactNode, useState } from 'react';

export interface ReportFilterValues {
    from: string;
    to: string;
    group_by?: string;
    [key: string]: string | number | null | undefined;
}

const iso = (date: Date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

function presets(): { label: string; from: string; to: string }[] {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();

    return [
        { label: 'Today', from: iso(now), to: iso(now) },
        { label: 'This month', from: iso(new Date(y, m, 1)), to: iso(now) },
        { label: 'Last month', from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) },
        { label: 'This year', from: iso(new Date(y, 0, 1)), to: iso(now) },
    ];
}

interface ReportFiltersProps {
    routeName: string;
    filters: ReportFilterValues;
    /** Show the day/month grouping selector. */
    grouping?: boolean;
    /** Extra filter fields; their values are read from `extra`. */
    children?: (values: ReportFilterValues, set: (key: string, value: string) => void) => ReactNode;
}

/**
 * Date range (with quick presets) and optional grouping, shared by every report.
 */
export function ReportFilters({ routeName, filters, grouping = false, children }: ReportFiltersProps) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [values, setValues] = useState<ReportFilterValues>(filters);
    const set = (key: string, value: string) => setValues((current) => ({ ...current, [key]: value }));

    const apply = (next: ReportFilterValues) =>
        router.get(route(routeName), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== null && v !== undefined)), {
            preserveState: true,
            replace: true,
        });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        apply(values);
    };

    return (
        <form onSubmit={submit} className="space-y-2 print:hidden">
            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <Input type="date" value={values.from} onChange={(e) => set('from', e.target.value)} className="sm:w-40" aria-label="From" />
                <Input type="date" value={values.to} onChange={(e) => set('to', e.target.value)} className="sm:w-40" aria-label="To" />
                {grouping && (
                    <select
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={values.group_by ?? 'day'}
                        onChange={(e) => set('group_by', e.target.value)}
                        aria-label="Group by"
                    >
                        <option value="day">By day</option>
                        <option value="month">By month</option>
                    </select>
                )}
                {children?.(values, set)}
                <Button type="submit" variant="secondary">
                    Apply
                </Button>
            </div>
            <div className="flex flex-wrap gap-1">
                {presets().map((preset) => (
                    <Button
                        key={preset.label}
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            const next = { ...values, from: preset.from, to: preset.to };
                            setValues(next);
                            apply(next);
                        }}
                    >
                        {preset.label}
                    </Button>
                ))}
            </div>
            <InputError message={errors.to ?? errors.from ?? errors.group_by} />
        </form>
    );
}
