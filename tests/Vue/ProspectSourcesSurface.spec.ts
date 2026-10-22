import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    role: 'owner',
    router: {
        post: vi.fn(),
        delete: vi.fn(),
    },
    form: {
        processing: false,
        post: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router: state.router,
    useForm: () => state.form,
    usePage: () => ({
        props: { auth: { workspace: { role: state.role } } },
    }),
}));

import ProspectSourceRun from '@/pages/prospect-sources/Run.vue';
import ProspectSourceShow from '@/pages/prospect-sources/Show.vue';
import type { ProspectSource, ProspectSourceRun as SourceRun } from '@/types';

const stubs = {
    AppWorkspace: {
        props: ['area', 'title'],
        template: '<div><slot /></div>',
    },
};

const run: SourceRun = {
    id: '00000000-0000-4000-8000-000000000002',
    state: 'failed',
    received: 2,
    valid: 2,
    accepted: 1,
    created: 1,
    linked: 0,
    duplicate: 0,
    conflicted: 0,
    suppressed: 1,
    rejected: 0,
    created_at: '2026-09-03T12:00:00Z',
};

const source: ProspectSource = {
    id: '00000000-0000-4000-8000-000000000001',
    display_name: 'September prospects',
    kind: 'csv_import',
    status: 'active',
    run_count: 1,
    accepted_count: 1,
    created_count: 1,
    linked_count: 0,
    suppressed_count: 1,
    rejected_count: 0,
    restrictions: [],
    retention_warning: null,
    last_run: run,
};

describe('Prospect source surfaces', () => {
    beforeEach(() => {
        state.role = 'owner';
        state.router.post.mockReset();
        state.router.delete.mockReset();
        state.form.post.mockReset();
    });

    it('shows archive, safe export, and queued deletion controls to owners', async () => {
        const wrapper = mount(ProspectSourceShow, {
            props: { source: { ...source, runs: [run], versions: [] } },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Export allowed data');
        expect(wrapper.text()).toContain('Archive');
        expect(wrapper.text()).toContain('Delete source data');

        await wrapper.get('button.danger').trigger('click');
        await wrapper
            .findAll('button.danger')
            .find((button) => button.text().includes('Confirm deletion'))
            ?.trigger('click');

        expect(state.router.delete).toHaveBeenCalledWith(
            `/prospect-sources/${source.id}/data`,
            expect.objectContaining({ data: { delete_canonical: false } }),
        );
    });

    it('renders source detail and run history as read-only for members', () => {
        state.role = 'member';
        const detail = mount(ProspectSourceShow, {
            props: { source: { ...source, runs: [run], versions: [] } },
            global: { stubs },
        });
        const runPage = mount(ProspectSourceRun, {
            props: {
                source,
                run: { ...run, observations: { data: [] } },
            },
            global: { stubs },
        });

        expect(detail.text()).toContain('read-only for members');
        expect(detail.text()).not.toContain('Delete source data');
        expect(detail.text()).not.toContain('Export allowed data');
        expect(runPage.text()).toContain(
            'Members can inspect safe run results',
        );
        expect(runPage.text()).not.toContain('Retry run');
        expect(runPage.text()).not.toContain('Download errors');
    });
});
