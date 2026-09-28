import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * The organization's uploaded logo (Settings → Organization), or the default mark when none is set.
 */
export function OrganizationLogo({ className, fallbackClassName }: { className?: string; fallbackClassName?: string }) {
    const { organization } = usePage<SharedData>().props;

    if (organization?.logo_url) {
        return <img src={organization.logo_url} alt={`${organization.name} logo`} className={cn('object-contain', className)} />;
    }

    return <AppLogoIcon className={cn('fill-current', fallbackClassName ?? className)} />;
}
