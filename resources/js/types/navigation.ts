import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type AppArea =
    | 'unibox'
    | 'campaigns'
    | 'objectives'
    | 'mailboxes'
    | 'people'
    | 'accounts'
    | 'sources'
    | 'data'
    | 'suppressions'
    | 'api-keys'
    | 'devices'
    | 'providers'
    | 'account'
    | 'gtm-profile'
    | 'offers'
    | 'managed-mail'
    | 'mail-experiments';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};
