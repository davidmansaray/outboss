import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

const stubs = {
    Link: {
        props: ['href'],
        emits: ['click'],
        template: '<a :href="href" @click="$emit(\'click\')"><slot /></a>',
    },
};

const page = {
    props: {
        auth: {
            user: {
                id: 1,
                name: 'David Mansaray',
                email: 'david@outboss.test',
            },
            workspace: {
                id: 10,
                name: 'Outboss',
                type: 'team',
                role: 'owner',
                avatar_url: null,
            },
            workspaces: [
                {
                    id: 10,
                    name: 'Outboss',
                    type: 'team',
                    role: 'owner',
                    avatar_url: null,
                },
                {
                    id: 11,
                    name: 'Client workspace',
                    type: 'team',
                    role: 'owner',
                    avatar_url: null,
                },
            ],
        },
    },
};

describe('AppWorkspace', () => {
    beforeEach(() => {
        const values = new Map<string, string>();
        vi.stubGlobal('localStorage', {
            clear: () => values.clear(),
            getItem: (key: string) => values.get(key) ?? null,
            setItem: (key: string, value: string) => values.set(key, value),
            removeItem: (key: string) => values.delete(key),
        });
    });

    it('organizes the P0 destinations around work, build, and learn', () => {
        const wrapper = mount(AppWorkspace, {
            props: { area: 'unibox', title: 'Unibox' },
            global: {
                stubs,
                config: { globalProperties: { $page: page } },
            },
        });

        const hrefs = wrapper
            .findAll('a')
            .map((link) => link.attributes('href'));
        expect(hrefs).toContain('/unibox?folder=all_replies');
        expect(hrefs).toContain('/campaigns');
        expect(hrefs).toContain('/prospects');
        expect(hrefs).toContain('/objectives');
        expect(wrapper.text()).toContain('Work');
        expect(wrapper.text()).not.toContain('Today');
        expect(wrapper.text()).toContain('Inbox');
        expect(wrapper.text()).toContain('Outreach');
        const brandLink = wrapper.get('.global-brand-link');
        expect(brandLink.attributes('aria-label')).toBe('OutBoss home');
        expect(brandLink.get('.global-brand-wordmark').attributes('src')).toBe(
            '/brand/outboss-wordmark.svg',
        );
        expect(wrapper.text()).toContain('Build');
        expect(wrapper.text()).toContain('Inventory');
        expect(wrapper.text()).toContain('Accounts');
        expect(wrapper.text()).toContain('People');
        expect(hrefs).toContain('/accounts');
        expect(hrefs).toContain('/prospect-sources');
        expect(wrapper.text()).toContain('Objectives');
        expect(wrapper.text()).toContain('Learn');
        expect(wrapper.text()).toContain('Reports');
        expect(
            wrapper.get('.global-sidebar-footer .workspace-control').text(),
        ).toContain('Outboss');
        expect(
            wrapper.get('.global-sidebar-footer .workspace-control').text(),
        ).toContain('Owner · Switch workspace');
        expect(wrapper.find('.top .workspace-control').exists()).toBe(false);
        expect(wrapper.find('.side .workspace-control').exists()).toBe(false);
        expect(wrapper.get('.top .account-menu-trigger').text()).toContain(
            'David',
        );
        expect(wrapper.find('.global-sidebar-footer').exists()).toBe(true);
        const collapse = wrapper.get('.global-sidebar-collapse');
        const footer = wrapper.get('.global-sidebar-footer');
        expect(
            collapse.element.compareDocumentPosition(footer.element) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(collapse.attributes('aria-label')).toBe('Collapse sidebar');
        expect(collapse.text()).toBe('');
        expect(wrapper.text()).not.toContain('Connections & sending');
        expect(
            wrapper.findAll('button.global-nav-item[disabled]'),
        ).toHaveLength(1);
        expect(
            wrapper
                .findAll('button.global-nav-item[disabled]')
                .every((item) => item.text().includes('Soon')),
        ).toBe(true);
        expect(wrapper.get('[aria-current="page"]').text()).toContain('Inbox');
        expect(wrapper.find('.top').exists()).toBe(true);
        expect(wrapper.get('.top-title').text()).toBe('Unibox');
    });

    it('persists the compact sidebar choice', async () => {
        const wrapper = mount(AppWorkspace, {
            props: { area: 'campaigns', title: 'Campaigns' },
            global: {
                stubs,
                config: { globalProperties: { $page: page } },
            },
        });

        await wrapper.get('[aria-label="Collapse sidebar"]').trigger('click');
        await nextTick();

        expect(wrapper.get('.app').classes()).toContain('global-collapsed');
        expect(localStorage.getItem('outboss_global_sidebar')).toBe(
            'collapsed',
        );
        expect(
            wrapper.get('.global-sidebar-collapse').attributes('aria-label'),
        ).toBe('Expand sidebar');
        expect(wrapper.get('[aria-current="page"]').text()).toContain(
            'Outreach',
        );
    });

    it('toggles from a sidebar double click without hijacking navigation', async () => {
        const wrapper = mount(AppWorkspace, {
            props: { area: 'unibox', title: 'Unibox' },
            global: {
                stubs,
                config: { globalProperties: { $page: page } },
            },
        });

        await wrapper.get('.global-sidebar').trigger('dblclick', { button: 0 });
        await nextTick();
        expect(wrapper.get('.app').classes()).toContain('global-collapsed');

        await wrapper.get('.global-sidebar').trigger('dblclick', { button: 0 });
        await nextTick();
        expect(wrapper.get('.app').classes()).not.toContain('global-collapsed');

        await wrapper
            .get('a[href="/unibox?folder=all_open"]')
            .trigger('dblclick', { button: 0 });
        await nextTick();
        expect(wrapper.get('.app').classes()).not.toContain('global-collapsed');
    });

    it('shows the header bar only when crumb or actions are provided', () => {
        const wrapper = mount(AppWorkspace, {
            props: { area: 'mailboxes', title: 'Mailboxes' },
            slots: {
                actions: '<a href="/oauth/zoho/redirect">Connect Zoho</a>',
            },
            global: {
                stubs,
                config: { globalProperties: { $page: page } },
            },
        });

        expect(wrapper.find('.top').exists()).toBe(true);
        expect(wrapper.get('.top a').text()).toBe('Connect Zoho');
        expect(wrapper.get('.workspace-crumb-parent').text()).toBe('Outboss');
        expect(wrapper.get('.workspace-crumb-separator').text()).toBe('›');
        expect(wrapper.get('.workspace-crumb-current').text()).toBe(
            'Mailboxes',
        );
    });
});
