import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { router } = vi.hoisted(() => ({
    router: {
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
    router,
    usePage: () => ({ props: { flash: {} } }),
}));

import MailExperimentsShow from '@/pages/mail-experiments/Show.vue';

const stubs = {
    AppWorkspace: { template: '<div><slot name="sidebar" /><slot /></div>' },
    WorkspaceOpsSidebar: { template: '<nav data-test="ops-sidebar" />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
};

const experiment = {
    id: 5,
    name: 'Domain strategy pilot',
    hypothesis: 'The subdomain strategy will perform comparably.',
    status: 'ready',
    primary_metric: 'human_reply_rate',
    target_assignments_per_cohort: 20,
    frozen_at: null,
    started_at: null,
    completed_at: null,
    cohorts: [
        {
            id: 8,
            key: 'a',
            label: 'Branded subdomain',
            domain_strategy: 'subdomain',
            snapshot_hash: null,
            campaign_id: null,
            campaign_name: null,
            mailboxes: [],
        },
        {
            id: 9,
            key: 'b',
            label: 'Separate roots',
            domain_strategy: 'separate_root_domains',
            snapshot_hash: null,
            campaign_id: null,
            campaign_name: null,
            mailboxes: [],
        },
    ],
};

describe('MailExperimentsShow', () => {
    beforeEach(() => router.post.mockReset());

    it('exposes balance, prepare, and freeze actions while keeping evidence recipient-level', async () => {
        const wrapper = mount(MailExperimentsShow, {
            props: {
                experiment,
                report: {
                    summary: {
                        assignment_count: 4,
                        exposed_count: 4,
                        human_reply_count: 1,
                        human_reply_rate: 0.25,
                        excluded_count: 0,
                    },
                    cohorts: {},
                    warnings: [],
                },
                managedMailboxes: [],
                campaigns: [],
                candidateCount: 4,
                canManage: true,
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('provider-submitted');
        expect(wrapper.text()).toContain('human reply rate');
        expect(wrapper.text()).toContain(
            'I confirm this exact audience passed compliance review.',
        );
        expect(wrapper.text()).toContain(
            'I confirm this exact audience passed legal review.',
        );

        const prepareButton = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Prepare')!;
        expect(prepareButton.attributes('disabled')).toBeDefined();
        const confirmations = wrapper.findAll(
            '.approval-option input[type="checkbox"]',
        );
        await confirmations[0].setValue(true);
        await confirmations[1].setValue(true);
        expect(prepareButton.attributes('disabled')).toBeUndefined();
        await wrapper.get('form.prepare-form').trigger('submit');
        expect(router.post).toHaveBeenCalledWith(
            '/settings/mail-experiments/5/prepare',
            {
                compliance_review_confirmed: true,
                legal_review_confirmed: true,
            },
            expect.objectContaining({ preserveScroll: true }),
        );

        await wrapper.get('button').trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/settings/mail-experiments/5/balance',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Freeze')!
            .trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/settings/mail-experiments/5/freeze',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows the persisted review evidence that will be frozen', () => {
        const wrapper = mount(MailExperimentsShow, {
            props: {
                experiment: {
                    ...experiment,
                    approval_evidence: {
                        compliance_review_confirmed: true,
                        legal_review_confirmed: true,
                        confirmed_at: '2026-09-01T12:00:00Z',
                        source: 'http',
                    },
                },
                report: { summary: {}, cohorts: {}, warnings: [] },
                managedMailboxes: [],
                campaigns: [],
                candidateCount: 4,
                canManage: true,
            },
            global: { stubs },
        });

        expect(wrapper.get('[data-test="approval-record"]').text()).toContain(
            'Compliance and legal review confirmed via http at 2026-09-01T12:00:00Z',
        );
    });

    it('only exposes resume for a paused experiment and posts the lifecycle action', async () => {
        const wrapper = mount(MailExperimentsShow, {
            props: {
                experiment: {
                    ...experiment,
                    status: 'paused',
                    frozen_at: '2026-09-01T12:00:00Z',
                },
                report: { summary: {}, cohorts: {}, warnings: [] },
                managedMailboxes: [],
                campaigns: [],
                candidateCount: 4,
                canManage: true,
            },
            global: { stubs },
        });

        expect(wrapper.get('[data-test="resume-experiment"]').text()).toBe(
            'Resume',
        );
        expect(
            wrapper
                .findAll('button')
                .some((button) => button.text() === 'Launch'),
        ).toBe(false);

        await wrapper.get('[data-test="resume-experiment"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/settings/mail-experiments/5/resume',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('does not render lifecycle actions without owner capability', () => {
        const wrapper = mount(MailExperimentsShow, {
            props: {
                experiment: {
                    ...experiment,
                    status: 'paused',
                    frozen_at: '2026-09-01T12:00:00Z',
                },
                report: { summary: {}, cohorts: {}, warnings: [] },
                managedMailboxes: [],
                campaigns: [],
                candidateCount: 4,
                canManage: false,
            },
            global: { stubs },
        });

        expect(wrapper.find('[data-test="resume-experiment"]').exists()).toBe(
            false,
        );
    });
});
