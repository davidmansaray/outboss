import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { router } = vi.hoisted(() => ({
    router: {
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
    usePage: () => ({
        props: { flash: {} },
    }),
}));

import SuppressionsIndex from '@/pages/suppressions/Index.vue';

const stubs = {
    AppWorkspace: {
        props: ['area', 'title'],
        template:
            '<div class="app" :data-area="area" :data-title="title"><aside><slot name="sidebar" /></aside><div class="top"><slot name="crumb" /></div><slot /></div>',
    },
};

const entries = [
    {
        id: 4,
        email: 'left@workflow.demo',
        reason: 'unsubscribe',
        created_at: '2026-08-28T11:00:00+00:00',
    },
    {
        id: 5,
        email: 'bounce@workflow.demo',
        reason: 'hard_bounce',
        created_at: '2026-08-27T09:00:00+00:00',
    },
];

describe('Suppressions index', () => {
    beforeEach(() => {
        router.delete.mockReset();
    });

    it('renders inside the product workspace shell', () => {
        const wrapper = mount(SuppressionsIndex, {
            props: { entries },
            global: { stubs },
        });

        expect(wrapper.get('.app').attributes('data-area')).toBe(
            'suppressions',
        );
        expect(wrapper.get('h1').text()).toBe('Suppressions');
        expect(wrapper.find('.suppressions-eyebrow').exists()).toBe(false);
        expect(wrapper.text()).toContain('left@workflow.demo');
        expect(wrapper.text()).toContain('Unsubscribed');
        expect(wrapper.text()).toContain('Hard bounce');
        expect(wrapper.get('a[href="/settings/exports"]').exists()).toBe(true);
        expect(
            wrapper
                .get('a[href="/settings/suppressions"]')
                .attributes('aria-current'),
        ).toBe('page');
    });

    it('shows the empty state when nothing is suppressed', () => {
        const wrapper = mount(SuppressionsIndex, {
            props: { entries: [] },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('No suppressed addresses');
        expect(wrapper.find('.suppressions-row').exists()).toBe(false);
    });

    it('asks for confirmation before removing a suppression', async () => {
        const wrapper = mount(SuppressionsIndex, {
            props: { entries },
            global: { stubs },
        });

        await wrapper
            .get('[data-test="remove-suppression-4"]')
            .trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
        expect(wrapper.get('[role="dialog"]').text()).toContain(
            'Remove left@workflow.demo?',
        );

        await wrapper
            .get('[data-test="cancel-remove-suppression"]')
            .trigger('click');

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('removes a suppression after confirmation', async () => {
        const wrapper = mount(SuppressionsIndex, {
            props: { entries },
            global: { stubs },
        });

        await wrapper
            .get('[data-test="remove-suppression-4"]')
            .trigger('click');
        await wrapper
            .get('[data-test="confirm-remove-suppression"]')
            .trigger('click');

        expect(router.delete).toHaveBeenCalledWith(
            '/settings/suppressions/4',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
