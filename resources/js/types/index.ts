import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Permission(s) required to see this item (all of them). Visibility only; the server still authorizes. */
    permission?: string | string[];
}

export interface FlashMessages {
    success?: string | null;
    error?: string | null;
    status?: string | null;
}

export interface Organization {
    name: string;
    logo_url: string | null;
}

export interface SharedData {
    /** Organization name (Settings → Organization). */
    name: string;
    organization: Organization;
    auth: Auth;
    flash: FlashMessages;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    roles: string[];
    permissions: string[];
    [key: string]: unknown; // This allows for additional properties...
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** Page metadata shared by Laravel paginators and API resource collections. */
export interface PageMeta {
    from: number | null;
    to: number | null;
    total: number;
    last_page: number;
    links: PaginationLink[];
}

/** A plain Laravel LengthAwarePaginator serialised by Inertia. */
export interface Paginator<T> extends PageMeta {
    data: T[];
    current_page: number;
}

export interface Paginated<T> {
    data: T[];
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        links: PaginationLink[];
        per_page: number;
        to: number | null;
        total: number;
    };
}

export interface PermissionGroup {
    group: string;
    permissions: { name: string; label: string }[];
}

export interface Option {
    id: number;
    name: string;
}
