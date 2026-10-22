import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import P0Sidebar from '@/components/P0Sidebar.vue';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';

const stubs = {
    Link: {
        props: ['href', 'ariaCurrent', 'ariaLabel'],
        template:
            '<a :href="href" :aria-current="ariaCurrent" :aria-label="ariaLabel"><slot /></a>',
    },
    WorkspaceSwitcher: { template: '<div data-test="workspace-switcher" />' },
};

describe('GTM navigation', () => {
    it('always exposes a real Offers link under Build', () => {
        const wrapper = mount(P0Sidebar, {
            props: { area: 'offers', collapsed: false, mobileOpen: false },
            global: {
                stubs,
            },
        });

        expect(wrapper.get('a[href="/offers"]').text()).toContain('Offers');
        expect(wrapper.findAll('.global-nav-group')[1].text()).toContain(
            'Offers',
        );

        wrapper.unmount();
    });

    it('exposes Objectives as a real primary workflow link', () => {
        const wrapper = mount(P0Sidebar, {
            props: { area: 'objectives', collapsed: false, mobileOpen: false },
            global: {
                stubs,
            },
        });

        expect(wrapper.get('a[href="/objectives"]').text()).toContain(
            'Objectives',
        );
        expect(
            wrapper.get('a[href="/objectives"]').attributes('aria-current'),
        ).toBe('page');

        wrapper.unmount();
    });

    it('always renders Strategy and keeps the four-group mobile nav usable', () => {
        const wrapper = mount(WorkspaceOpsSidebar, {
            props: { current: 'gtm-profile' },
            global: {
                stubs: { Link: stubs.Link },
            },
        });

        expect(wrapper.text()).toContain('Strategy');
        expect(
            wrapper
                .get('a[href="/settings/gtm-profile"]')
                .attributes('aria-current'),
        ).toBe('page');
        expect(wrapper.find('.ops-side-nav').element.outerHTML).toContain(
            'ops-side-nav',
        );

        wrapper.unmount();
    });
});
