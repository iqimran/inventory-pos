import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export const reportLinks = [
    { routeName: 'reports.sales', title: 'Sales' },
    { routeName: 'reports.product-revenue', title: 'Product revenue' },
    { routeName: 'reports.service-revenue', title: 'Service revenue' },
    { routeName: 'reports.revenue', title: 'Combined revenue' },
    { routeName: 'reports.stock', title: 'Stock' },
    { routeName: 'reports.parties', title: 'Party ledger' },
    { routeName: 'expenses.report', title: 'Expenses' },
];

/**
 * Tabs between reports; the date range is carried over.
 */
export function ReportNav({ current, range }: { current: string; range?: { from: string; to: string } }) {
    return (
        <nav className="flex flex-wrap gap-1 border-b pb-2 print:hidden" aria-label="Reports">
            {reportLinks.map((link) => (
                <Link
                    key={link.routeName}
                    href={route(link.routeName, range ?? {})}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-sm',
                        link.routeName === current ? 'bg-primary text-primary-foreground' : 'hover:bg-muted text-muted-foreground',
                    )}
                >
                    {link.title}
                </Link>
            ))}
        </nav>
    );
}
