import { OrganizationLogo } from '@/components/organization-logo';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export default function AppLogo() {
    const { name, organization } = usePage<SharedData>().props;
    const hasLogo = Boolean(organization?.logo_url);

    return (
        <>
            <div
                className={cn(
                    'flex aspect-square size-8 shrink-0 items-center justify-center overflow-hidden rounded-md',
                    hasLogo ? 'bg-white' : 'bg-sidebar-primary text-sidebar-primary-foreground',
                )}
            >
                <OrganizationLogo className="size-8" fallbackClassName="size-5 text-white dark:text-black" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">{name}</span>
            </div>
        </>
    );
}
