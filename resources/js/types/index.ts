export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: any;
    isActive?: boolean;
    /** Count shown beside the link; "alert" when something there is overdue. null: can show one, nothing waiting now. */
    badge?: { count: number; tone: 'default' | 'alert'; label: string } | null;
}

/** Shared deferred prop behind the sidebar badges (SidebarCountService); null where the user cannot see that page. */
export interface SidebarCounts {
    transactions: { open: number; delinquent: number };
    admApprovals: number | null;
    ocmApprovals: number | null;
    untaggedDelinquent: number | null;
}

export interface NavSection {
    label: string;
    items: NavItem[];
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
