import { FlashMessages } from '@/components/flash-messages';
import { OrganizationLogo } from '@/components/organization-logo';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    const { name } = usePage<SharedData>().props;

    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <FlashMessages />
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link href={route('login')} className="flex flex-col items-center gap-2 font-medium">
                            <div className="mb-1 flex h-16 max-w-48 items-center justify-center">
                                <OrganizationLogo
                                    className="h-16 w-auto max-w-48"
                                    fallbackClassName="size-9 text-[var(--foreground)] dark:text-white"
                                />
                            </div>
                            <span className="text-lg font-semibold">{name}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-muted-foreground text-center text-sm">{description}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
