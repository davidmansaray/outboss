import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, reload } = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router: { post, reload },
    usePage: () => ({
        props: {
            flash: {},
            errors: {},
        },
    }),
}));

vi.mock('@lucide/vue', () => ({
    ArrowLeft: { template: '<span aria-hidden="true" />' },
    ExternalLink: { template: '<span aria-hidden="true" />' },
    RefreshCw: { template: '<span aria-hidden="true" />' },
    ShieldCheck: { template: '<span aria-hidden="true" />' },
}));

import Intelligence from '@/pages/accounts/Intelligence.vue';
import type { AccountIntelligencePageProps } from '@/types/accountIntelligence';

const stubs = {
    AppWorkspace: {
        props: ['area', 'title'],
        template:
            '<div class="workspace" :data-area="area"><slot name="crumb" /><slot /></div>',
    },
};

const baseProps: AccountIntelligencePageProps = {
    account: {
        id: 42,
        name: 'Acme Intelligence',
        website: 'https://acme.example.com',
        domain: 'acme.example.com',
        notes: null,
        tags: [],
        source: 'manual',
        provenance: {
            primary_source: null,
            source_count: 0,
            last_observed_at: null,
            has_conflicts: false,
            has_restrictions: false,
            source_omission_reason: null,
        },
        supplied_by: {
            source: null,
            source_count: 0,
            last_observed_at: null,
            has_conflicts: false,
            has_restrictions: false,
            omission_reason: null,
        },
    },
    provenance: {
        primary_source: null,
        source_count: 0,
        last_observed_at: null,
        has_conflicts: false,
        has_restrictions: false,
        source_omission_reason: null,
    },
    preflight: {
        account_id: 42,
        input_hash: 'a'.repeat(64),
        blocked: false,
        blockers: [],
        stages: [],
        account_snapshot: {
            id: 42,
            name: 'Acme Intelligence',
            website: 'https://acme.example.com',
            domain_normalized: 'acme.example.com',
            tags: [],
            source: 'manual',
        },
        policy_snapshot: {},
        capability_snapshot: {
            selected: [],
            required: {},
            providers: {},
        },
        freshness_policy: {
            snapshot_max_age_hours: 168,
            claim_max_age_hours: 720,
        },
        estimated_cost_micros: 100,
        maximum_cost_micros: 1000,
        currency: 'USD',
        gtm_profile_version_id: 'profile-version-1',
        offer_version_id: null,
    },
    researchRun: null,
    researchRuns: [],
    intelligence: {
        claims: [],
        by_status: {},
        status_counts: {},
        conflicts: [],
        missing_facts: [],
    },
    qualification: null,
    offer: {
        id: 'offer-1',
        status: 'draft',
        availability_state: 'ready_for_review',
        lock_version: 1,
        current_version_id: 'offer-version-1',
        linked_version_id: 'offer-version-1',
        summary: {
            name: 'Account review',
            promise: 'A bounded review',
            target_audience: 'Revenue teams',
            call_to_action: 'Book a review',
            fulfilment_mode: 'human_assisted',
            destination_url: null,
        },
        linked_version: {
            id: 'offer-version-1',
            version: 2,
            schema_version: 2,
            content_hash: 'offer-hash',
            summary: {
                name: 'Account review',
                promise: 'A bounded review',
                target_audience: 'Revenue teams',
                call_to_action: 'Book a review',
                fulfilment_mode: 'human_assisted',
                destination_url: null,
            },
        },
        readiness: {
            id: 'readiness-1',
            offer_id: 'offer-1',
            offer_version_id: 'offer-version-1',
            state: 'ready_for_review',
            blockers: [],
            evaluated_at: null,
            valid_until: null,
        },
    },
    conversion: {
        definitions: [],
        events: [],
        event_count: 0,
        attribution_state: 'not_evaluated',
    },
    selectedRunId: null,
    isOwner: true,
    canWrite: true,
};

describe('Account intelligence page', () => {
    beforeEach(() => {
        post.mockReset();
        reload.mockReset();
    });

    it('renders the S1 identity and server preflight with an owner start control', () => {
        const wrapper = mount(Intelligence, {
            props: baseProps,
            global: { stubs },
        });

        expect(wrapper.get('h1').text()).toBe('Acme Intelligence');
        expect(wrapper.get('[data-test="account-identity-card"]').text()).toContain(
            'Supplied by S1',
        );
        expect(wrapper.get('[data-test="account-intelligence-preflight"]').text()).toContain(
            'Before any provider call',
        );
        expect(
            wrapper.get('[data-test="start-account-research"]').attributes('disabled'),
        ).toBeUndefined();

        wrapper.get('[data-test="start-account-research"]').trigger('click');

        expect(post).toHaveBeenCalledWith(
            '/accounts/42/intelligence/research-runs',
            expect.objectContaining({
                preflight_hash: 'a'.repeat(64),
                maximum_cost_micros: 1000,
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
        wrapper.unmount();
    });

    it('keeps owner-only controls unavailable to members and still shows status text', () => {
        const wrapper = mount(Intelligence, {
            props: {
                ...baseProps,
                isOwner: false,
                canWrite: false,
                preflight: {
                    ...baseProps.preflight,
                    blocked: true,
                    blockers: [
                        {
                            code: 'provider_unavailable',
                            message: 'Provider setup is required.',
                        },
                    ],
                },
            },
            global: { stubs },
        });

        const start = wrapper.get('[data-test="start-account-research"]');

        expect(start.attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="qualification-accept"]').exists()).toBe(
            false,
        );
        expect(wrapper.get('[data-test="account-intelligence-preflight"]').text()).toContain(
            'Provider setup is required.',
        );
        expect(wrapper.get('[role="status"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('exposes readiness and version-review controls through session routes', () => {
        post.mockImplementation(
            (
                _url: string,
                _payload: unknown,
                options: { onFinish?: () => void },
            ) => options.onFinish?.(),
        );
        const wrapper = mount(Intelligence, {
            props: baseProps,
            global: { stubs },
        });

        wrapper.get('[data-test="offer-readiness-refresh"]').trigger('click');
        expect(post).toHaveBeenCalledWith(
            '/offers/offer-1/readiness',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );

        wrapper.get('[data-test="offer-submit-review"]').trigger('click');
        expect(post).toHaveBeenLastCalledWith(
            '/offers/offer-1/review',
            { action: 'submit', reason: null },
            expect.objectContaining({ preserveScroll: true }),
        );
        wrapper.unmount();
    });

    it('renders a preserved prior Claim after an owner correction', async () => {
        const correctedClaim = {
            claim_id: 'claim-corrected',
            predicate: 'company.positioning',
            value: 'Owner-confirmed positioning',
            status: 'owner_confirmed',
            confidence: 1,
            observed_at: null,
            fresh_until: null,
            evidence_ids: [],
            evidence: [],
            history: [
                {
                    claim_id: 'claim-original',
                    predicate: 'company.positioning',
                    value: 'Conflicting positioning',
                    value_type: 'string',
                    status: 'conflicting',
                    confidence: 0.5,
                    reason: 'Owner resolved the public wording.',
                    superseded_at: '2026-09-04T00:00:00Z',
                    evidence: [],
                },
            ],
        };
        const wrapper = mount(Intelligence, {
            props: {
                ...baseProps,
                intelligence: {
                    claims: [correctedClaim],
                    by_status: { owner_confirmed: [correctedClaim] },
                    status_counts: { owner_confirmed: 1 },
                    conflicts: [],
                    missing_facts: [],
                },
            },
            global: { stubs },
        });

        const history = wrapper.get('.claim-history');
        await history.get('summary').trigger('click');

        expect(history.text()).toContain('Prior version (1)');
        expect(history.text()).toContain('Conflicting positioning');
        expect(history.text()).toContain('claim-original');
        expect(history.text()).toContain('Owner resolved the public wording.');
        wrapper.unmount();
    });
});
