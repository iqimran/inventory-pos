import { useCan } from '@/hooks/use-can';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

// Mirrors App\Domain\Reporting\ReportAccess: reports.view plus the module the report exposes.
export const reportLinks = [
    { routeName: 'reports.sales', title: 'Sales', permission: ['reports.view', 'sales.view'] },
    { routeName: 'reports.product-revenue', title: 'Product revenue', permission: ['reports.view', 'sales.view'] },
    { routeName: 'reports.service-revenue', title: 'Service revenue', permission: ['reports.view', 'service.view'] },
    { routeName: 'reports.revenue', title: 'Combined revenue', permission: ['reports.view', 'sales.view', 'service.view'] },
    { routeName: 'reports.stock', title: 'Stock', permission: ['reports.view', 'inventory.view'] },
    { routeName: 'reports.parties', title: 'Party ledger', permission: ['reports.view', 'parties.view'] },
    { routeName: 'expenses.report', title: 'Expenses', permission: ['expenses.view'] },
];

/**
 * Tabs between reports; the date range is carried over.
 */
export function ReportNav({ current, range }: { current: string; range?: { from: string; to: string } }) {
    const can = useCan();

    return (
        <nav className="flex flex-wrap gap-1 border-b pb-2 print:hidden" aria-label="Reports">
            {reportLinks
                .filter((link) => can(link.permission))
                .map((link) => (
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
