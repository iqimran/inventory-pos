import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ReactNode } from 'react';

interface StatCardProps {
    label: string;
    value: ReactNode;
    sub?: ReactNode;
    href?: string;
    emphasis?: boolean;
}

export function StatCard({ label, value, sub, href, emphasis }: StatCardProps) {
    const body = (
        <Card className={cn('h-full', href && 'hover:bg-muted/40 transition-colors', emphasis && 'border-primary')}>
            <CardContent className="space-y-1 pt-6">
                <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</div>
                <div className="text-2xl font-semibold tabular-nums">{value}</div>
                {sub && <div className="text-muted-foreground text-xs">{sub}</div>}
            </CardContent>
        </Card>
    );

    return href ? (
        <Link href={href} className="block">
            {body}
        </Link>
    ) : (
        body
    );
}
