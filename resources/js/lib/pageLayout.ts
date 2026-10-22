const standaloneWorkspacePrefixes = [
    'settings/',
    'mailboxes/',
    'data-export/',
    'suppressions/',
    'api-keys/',
    'providers/',
    'unibox/',
    'campaigns/',
    'execution-plans/',
    'objectives/',
    'people/',
    'accounts/',
    'prospect-sources/',
    'gtm-profile/',
    'offers/',
    'managed-mail/',
    'mail-experiments/',
] as const;

/** These pages provide their own complete Outboss workspace chrome. */
export function isStandaloneWorkspacePage(name: string): boolean {
    return standaloneWorkspacePrefixes.some((prefix) =>
        name.startsWith(prefix),
    );
}
