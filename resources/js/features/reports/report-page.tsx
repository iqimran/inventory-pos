import Heading from '@/components/heading';
import { ReportNav } from '@/features/reports/report-nav';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { ReactNode } from 'react';

/**
 * Layout shared by the reports: breadcrumbs, report tabs and heading.
 */
export function ReportPage({
    title,
    description,
    routeName,
    range,
    children,
}: {
    title: string;
    description: string;
    routeName: string;
    range: { from: string; to: string };
    children: ReactNode;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Reports', href: route('reports.sales', range) },
                { title, href: route(routeName, range) },
            ]}
        >
            <Head title={title} />
            <div className="space-y-6 p-4 md:p-6">
                <ReportNav current={routeName} range={range} />
                <Heading title={title} description={`${description} · ${range.from} → ${range.to}`} />
                {children}
            </div>
        </AppLayout>
    );
}

export const th = 'px-3 py-2 font-medium';
export const thRight = 'px-3 py-2 text-right font-medium';
export const td = 'px-3 py-2';
export const tdRight = 'px-3 py-2 text-right tabular-nums';
