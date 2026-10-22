import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

import DataExport from '@/pages/data-export/Index.vue';

const stubs = {
    AppWorkspace: {
        props: ['area', 'title'],
        template:
            '<div class="app" :data-area="area" :data-title="title"><aside><slot name="sidebar" /></aside><div class="top"><slot name="crumb" /></div><slot /></div>',
    },
};

describe('Data export', () => {
    it('renders inside the product workspace shell', () => {
        const wrapper = mount(DataExport, {
            props: { suppressionsCount: 2 },
            global: { stubs },
        });

        expect(wrapper.get('.app').attributes('data-area')).toBe('data');
        expect(wrapper.get('.app').attributes('data-title')).toBe('Exports');
        expect(wrapper.get('.export-index').exists()).toBe(true);
        expect(wrapper.get('h1').text()).toBe('Exports');
        expect(wrapper.find('.export-eyebrow').exists()).toBe(false);
        expect(wrapper.text()).toContain('2 suppressions');
        expect(wrapper.get('a[href="/settings/mailboxes"]').exists()).toBe(
            true,
        );
        expect(wrapper.get('a[href="/settings/suppressions"]').exists()).toBe(
            true,
        );
        expect(
            wrapper
                .get('a[href="/settings/exports"]')
                .attributes('aria-current'),
        ).toBe('page');
    });

    it('lists all seven workspace export downloads for owners', () => {
        const wrapper = mount(DataExport, { global: { stubs } });

        expect(
            wrapper.get('[data-test="export-accounts"]').attributes('href'),
        ).toBe('/accounts/export');
        expect(
            wrapper.get('[data-test="export-people"]').attributes('href'),
        ).toBe('/people/export');
        expect(
            wrapper.get('[data-test="export-enrollments"]').attributes('href'),
        ).toBe('/exports/enrollments');
        expect(
            wrapper.get('[data-test="export-sources"]').attributes('href'),
        ).toBe('/exports/sources');
        expect(
            wrapper.get('[data-test="export-integrations"]').attributes('href'),
        ).toBe('/exports/integrations');
        expect(
            wrapper.get('[data-test="export-suppressions"]').attributes('href'),
        ).toBe('/exports/suppressions');
        expect(
            wrapper
                .get('[data-test="export-campaign-outcomes"]')
                .attributes('href'),
        ).toBe('/exports/campaign-outcomes');
        expect(wrapper.text()).toContain('Enrolment snapshots');
        expect(wrapper.text()).toContain('docs/export-formats.md');
        expect(wrapper.findAll('.export-row')).toHaveLength(7);
    });

    it('does not offer owner-only source and integration exports to members', () => {
        const wrapper = mount(DataExport, {
            props: { isOwner: false },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="export-sources"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="export-integrations"]').exists()).toBe(
            false,
        );
        expect(wrapper.findAll('.export-row')).toHaveLength(5);
    });
});
