import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { router } = vi.hoisted(() => ({
    router: {
        post: vi.fn(),
        delete: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router,
    usePage: () => ({ props: { flash: {} } }),
}));

import ManagedMailIndex from '@/pages/managed-mail/Index.vue';

const stubs = {
    AppWorkspace: {
        template: '<div><slot name="sidebar" /><slot /></div>',
        props: ['area', 'title', 'showSidebarTitle'],
    },
    WorkspaceOpsSidebar: { template: '<nav data-test="ops-sidebar" />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
};

const domain = {
    id: 7,
    domain: 'outreach.example.com',
    organisational_domain: 'example.com',
    domain_kind: 'subdomain',
    provider: 'stalwart',
    managed_instance_key: 'internal',
    remote_domain_id: 'fake-domain-7',
    status: 'active',
    dns_records: {
        mx: { host: '@', value: 'mx.example.test' },
        verification: { mx: 'pass', spf: 'pass', dmarc: 'pass' },
    },
    registered_at: null,
    dns_activated_at: null,
    verified_at: '2026-09-01T00:00:00+00:00',
    last_verified_at: '2026-09-01T00:00:00+00:00',
    last_error_code: null,
    last_error_message: null,
    mailbox_accounts: [
        {
            id: 11,
            mailbox_id: 22,
            email: 'sales@outreach.example.com',
            local_part: 'sales',
            display_name: 'Sales',
            credential_version: 2,
            status: 'active',
            provisioned_at: null,
            last_verified_at: null,
            last_error_code: null,
            last_error_message: null,
            mailbox_status: 'connected',
            connection_status: 'connected',
            sending_paused_at: null,
            sending_paused_reason: null,
        },
    ],
};

describe('ManagedMailIndex', () => {
    beforeEach(() => {
        router.post.mockReset();
        router.delete.mockReset();
        vi.restoreAllMocks();
    });

    it('shows public DNS evidence and sends owner actions to internal routes', async () => {
        const wrapper = mount(ManagedMailIndex, {
            props: {
                domains: [domain],
                defaultInstance: 'internal',
                instances: {
                    internal: {
                        base_url: 'https://mx-api.example.test',
                        verify_tls: true,
                        timeout_seconds: 15,
                    },
                },
                canManage: true,
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('mx.example.test');
        expect(wrapper.text()).toContain('sales@outreach.example.com');
        expect(wrapper.text()).not.toContain('password');
        expect(wrapper.get('label[for="mailbox-email-7"]').text()).toBe(
            'Email address',
        );
        expect(wrapper.text()).toContain(
            'Enter the part before @outreach.example.com.',
        );
        expect(wrapper.get('label[for="mailbox-sender-7"]').text()).toBe(
            'Sender name',
        );
        expect(wrapper.text()).toContain(
            'The name recipients will see in their inbox.',
        );

        await wrapper
            .get('[data-test="managed-domain-7"] button')
            .trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/settings/managed-mail/domains/7/verify',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );

        await wrapper
            .get('[data-test="managed-mailbox-11"]')
            .get('button')
            .trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/settings/managed-mail/mailboxes/11/rotate-credential',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('does not render mutation controls when the internal policy prop is false', () => {
        const wrapper = mount(ManagedMailIndex, {
            props: {
                domains: [domain],
                defaultInstance: 'internal',
                instances: {},
                canManage: false,
            },
            global: { stubs },
        });

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Rotate credential');
        expect(wrapper.text()).not.toContain('Suspend');
    });

    it('renders the complete zone file and retires a domain after confirmation', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const zoneFile = Array.from(
            { length: 80 },
            (_, index) =>
                `selector${index}._domainkey.example.com. IN TXT "v=DKIM1; p=${index}"`,
        ).join('\n');
        const retired = {
            ...domain,
            dns_records: { zone_file: zoneFile },
            mailbox_accounts: domain.mailbox_accounts.map((account) => ({
                ...account,
                status: 'deleted',
            })),
        };
        const wrapper = mount(ManagedMailIndex, {
            props: {
                domains: [retired],
                defaultInstance: 'internal',
                instances: {},
                canManage: true,
            },
            global: { stubs },
        });

        expect(wrapper.get('.zone-file').text()).toBe(zoneFile);
        await wrapper
            .findAll('[data-test="managed-domain-7"] button')
            .find((button) => button.text() === 'Retire domain')!
            .trigger('click');

        expect(router.delete).toHaveBeenCalledWith(
            '/settings/managed-mail/domains/7',
            expect.objectContaining({
                data: { confirm: true },
                preserveScroll: true,
            }),
        );
    });
});
