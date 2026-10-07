import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/**
 * Returns a checker for the current user's permissions (Admin holds all). Several permissions = all required.
 * UI convenience only — every action is authorized again on the server.
 */
export function useCan() {
    const permissions = usePage<SharedData>().props.auth.user?.permissions;

    return useCallback(
        (permission?: string | string[]) =>
            !permission || (Array.isArray(permission) ? permission : [permission]).every((name) => (permissions ?? []).includes(name)),
        [permissions],
    );
}
