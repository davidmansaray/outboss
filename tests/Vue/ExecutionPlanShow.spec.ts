import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const routerPost = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router: { post: routerPost },
}));

vi.mock('@lucide/vue', () => ({
    ShieldCheck: { template: '<span aria-hidden="true" />' },
}));

import ExecutionPlanShow from '@/pages/execution-plans/Show.vue';

const hash = (character: string) => character.repeat(64);
const emptyOutcomes = {
    exposures: {
        total: 0,
        provider_accepted: 0,
        confirmed_sent: 0,
        uncertain: 0,
    },
    conversations: { total: 0, replied: 0, stopped: 0 },
    conversions: { total: 0 },
    attributions: { attributed: 0, ambiguous: 0, rejected: 0, pending: 0 },
};
const emptyLineage = { exposures: [], conversations: [], conversions: [] };
const plan = {
    id: 'plan-1',
    version: 1,
    status: 'partial',
    created_at: '2026-09-04T12:00:00Z',
    compiled_at: null,
    superseded_at: null,
    created_by: { id: 1, name: 'Plan Owner' },
    objective: {
        id: 'objective-1',
        run_id: 'run-1',
        version_id: 'objective-version-1',
        name: 'Book qualified workshops',
        target_kind: 'count',
        target_count: 2,
        target_value: null,
        currency: null,
        starts_at: '2026-09-05T12:00:00Z',
        ends_at: '2026-09-14T12:00:00Z',
    },
    lineage: {
        audience_snapshot_id: 'snapshot-1',
        gtm_experiment_id: 'experiment-1',
        experiment_name: 'Approval review experiment',
        conversion_definition_version_id: 'conversion-version-1',
        offer_version_id: 'offer-version-1',
        outreach_policy_version_id: 'policy-version-1',
    },
    model: {
        id: 'openai/test-model',
        ai_task_version: 'message_drafting_v1',
        prompt_version: 'objective_message_prompt_v1',
        schema_version: 'message_drafting_response_v1',
        validator_version: 'execution_plan_validator_v1',
    },
    counts: { arms: 1, accounts: 1, people: 1, actions: 1, omissions: 1 },
    audience_counts: { included: 2, excluded: 3, uncertain: 4, suppressed: 5 },
    costs: {
        maximum_micros: 500_000,
        estimated_micros: 1_200,
        actual_micros: 900,
    },
    hashes: {
        run_input: hash('a'),
        evidence_manifest: hash('b'),
        canonical_payload: hash('c'),
        plan: hash('d'),
    },
    omissions: [{ member_id: 'member-2', reason: 'recipient_suppressed' }],
    actions: [
        {
            id: 'action-1',
            position: 1,
            kind: 'email_new_thread',
            status: 'awaiting_approval',
            recipient: {
                address: 'ada@example.test',
                person_id: 10,
                account_id: 20,
                audience_member_id: 'member-1',
            },
            sender: {
                address: 'owner@example.test',
                display_name: 'Objective Owner',
                reply_to: 'replies@example.test',
                mailbox_id: 30,
                provider_connection_id: 40,
                provider_connection_version: 7,
            },
            subject: 'A plan for Acme',
            body_text: 'Hi Ada,\n\nWould you like to review the evidence?',
            body_html: '<p>Hi Ada</p>',
            schedule: {
                not_before: '2026-09-05T12:00:00Z',
                not_after: '2026-09-14T12:00:00Z',
            },
            selected_claim_ids: ['claim-1'],
            selected_evidence_ids: ['evidence-1'],
            selected_claim_safety_hashes: {
                'claim-1': hash('5'),
            },
            evidence_review: {
                reason_to_contact_version_id: 'reason-to-contact-version-1',
                fresh_until: '2026-10-04T12:00:00Z',
            },
            offer: {
                content_hash: hash('6'),
                contract_hash: hash('7'),
                destination_url: 'https://example.test/workshop',
                response_route: 'calendar',
            },
            lineage: {
                experiment_arm_id: 'arm-1',
                experiment_assignment_id: 'assignment-1',
                objective_run_arm_draft_id: 'draft-1',
                strategy_version_id: 'strategy-version-1',
                offer_version_id: 'offer-version-1',
                conversion_definition_version_id: 'conversion-version-1',
            },
            hashes: {
                destination_email: hash('e'),
                template: hash('f'),
                recipient_snapshot: hash('1'),
                content: hash('2'),
                policy_input: hash('3'),
                action_fingerprint: hash('4'),
            },
        },
    ],
};
const approval = {
    state: 'pending',
    batch_id: null,
    plan_hash: hash('d'),
    display_manifest_hash: hash('8'),
    action_fingerprints: [hash('4')],
    action_count: 1,
    policy_version_id: 'policy-version-1',
    expires_at: null,
    decided_at: null,
    decision_by: null,
    reason_code: null,
    reason_detail: null,
    blockers: [
        {
            code: 'plan_state_invalid',
            message: 'This partial plan cannot be approved.',
        },
    ],
    warnings: [],
    available_actions: [],
    consequence:
        'Approval grants authority only for these exact action fingerprints until expiry; it does not launch or send email.',
    grants: [],
};

describe('Execution plan review', () => {
    it('renders human plan lineage without an experiment', () => {
        const wrapper = mount(ExecutionPlanShow, {
            props: {
                plan: {
                    ...plan,
                    lineage: {
                        ...plan.lineage,
                        gtm_experiment_id: null,
                        experiment_name: null,
                    },
                },
                approval,
                safety: {
                    creates_campaign: false,
                    grants_sending_authority: false,
                    available_actions: [],
                },
            },
            global: {
                stubs: { AppWorkspace: { template: '<div><slot /></div>' } },
            },
        });
        expect(wrapper.text()).toContain('Not part of an experiment');
        expect(wrapper.text()).toContain('Not applicable');
    });

    it('renders the exact action manifest and no approval control', () => {
        const wrapper = mount(ExecutionPlanShow, {
            props: {
                plan,
                approval,
                safety: {
                    creates_campaign: false,
                    grants_sending_authority: false,
                    available_actions: [],
                },
            },
            global: {
                stubs: {
                    AppWorkspace: {
                        props: ['area', 'title'],
                        template:
                            '<div><aside><slot name="sidebar" /></aside><slot /></div>',
                    },
                },
            },
        });

        expect(wrapper.text()).toContain('Exact owner approval checkpoint');
        expect(wrapper.text()).toContain('creates no external effect');
        expect(wrapper.text()).toContain(
            'There is no approval action on this page',
        );
        expect(wrapper.text()).toContain('ada@example.test');
        expect(wrapper.text()).toContain(
            'Objective Owner <owner@example.test>',
        );
        expect(wrapper.text()).toContain('A plan for Acme');
        expect(wrapper.text()).toContain(
            'Would you like to review the evidence?',
        );
        expect(wrapper.text()).toContain('claim-1');
        expect(wrapper.text()).toContain('evidence-1');
        expect(wrapper.text()).toContain('Frozen Offer contract');
        expect(wrapper.text()).toContain('CTA destination URL');
        expect(wrapper.text()).toContain('https://example.test/workshop');
        expect(wrapper.text()).toContain('CTA response route');
        expect(wrapper.text()).toContain('calendar');
        expect(wrapper.text()).toContain('Offer content hash');
        expect(wrapper.text()).toContain(hash('6'));
        expect(wrapper.text()).toContain('Offer contract hash');
        expect(wrapper.text()).toContain(hash('7'));
        expect(wrapper.text()).toContain('Selected Claim safety hashes');
        expect(
            wrapper.find('.claim-safety-review .claim-safety-list').text(),
        ).toContain('claim-1');
        expect(
            wrapper.find('.claim-safety-review .claim-safety-list').text(),
        ).toContain(hash('5'));
        expect(wrapper.find('.evidence-review').text()).toContain(
            'reason-to-contact-version-1',
        );
        expect(wrapper.find('.evidence-review').text()).toContain(
            '2026-10-04T12:00:00Z',
        );
        expect(wrapper.text()).toContain('member-2');
        expect(wrapper.text()).toContain('Recipient Suppressed');
        expect(wrapper.text()).toContain(hash('d'));
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('keeps redacted action content and missing frozen contract data explicit', () => {
        const redactedPlan = {
            ...plan,
            actions: [
                {
                    ...plan.actions[0],
                    recipient: {
                        ...plan.actions[0].recipient,
                        address: null,
                        person_id: null,
                        account_id: null,
                    },
                    subject: null,
                    body_text: null,
                    body_html: null,
                    selected_claim_safety_hashes: {},
                    evidence_review: {
                        reason_to_contact_version_id: null,
                        fresh_until: null,
                    },
                    offer: {
                        content_hash: null,
                        contract_hash: null,
                        destination_url: null,
                        response_route: null,
                    },
                },
            ],
        };
        const wrapper = mount(ExecutionPlanShow, {
            props: {
                plan: redactedPlan,
                approval,
                safety: {
                    creates_campaign: false,
                    grants_sending_authority: false,
                    available_actions: [],
                },
            },
            global: {
                stubs: {
                    AppWorkspace: {
                        props: ['area', 'title'],
                        template:
                            '<div><aside><slot name="sidebar" /></aside><slot /></div>',
                    },
                },
            },
        });

        expect(wrapper.text()).toContain('Redacted recipient');
        expect(wrapper.text()).toContain('Redacted subject');
        expect(wrapper.text()).toContain('Message content redacted.');
        expect(wrapper.text()).toContain('None recorded');
        expect(wrapper.find('.evidence-review').text()).toContain(
            'Not recorded',
        );
        expect(wrapper.find('.evidence-review').text()).toContain('Not set');
        expect(wrapper.text().match(/Not recorded/g)?.length).toBe(5);
    });

    it('echoes the complete plan, display, and per-action hashes on approval', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const currentPlan = { ...plan, status: 'awaiting_approval' };
        const actionableApproval = {
            ...approval,
            blockers: [],
            available_actions: ['approve', 'reject'],
        };
        const wrapper = mount(ExecutionPlanShow, {
            props: {
                plan: currentPlan,
                approval: actionableApproval,
                safety: {
                    creates_campaign: false,
                    grants_sending_authority: false,
                    available_actions: ['approve', 'reject'],
                },
            },
            global: {
                stubs: {
                    AppWorkspace: {
                        props: ['area', 'title'],
                        template:
                            '<div><aside><slot name="sidebar" /></aside><slot /></div>',
                    },
                },
            },
        });

        await wrapper.find('.approval-controls button').trigger('click');

        expect(routerPost).toHaveBeenCalledWith(
            '/execution-plans/plan-1/approve',
            expect.objectContaining({
                plan_hash: hash('d'),
                display_manifest_hash: hash('8'),
                action_fingerprints: [hash('4')],
            }),
            expect.objectContaining({
                headers: expect.objectContaining({
                    'Idempotency-Key': expect.any(String),
                }),
            }),
        );
        expect(wrapper.text()).toContain('Approve exact batch');
        expect(wrapper.text()).toContain(hash('8'));
    });

    it('prepares only the exact approved plan snapshot without a send confirmation', async () => {
        routerPost.mockClear();
        const currentPlan = { ...plan, status: 'approved' };
        const execution = {
            is_owner: true,
            state: 'approved',
            plan_id: currentPlan.id,
            plan_hash: currentPlan.hashes.plan,
            available_actions: ['compile'],
            blockers: [],
            latest_compilation: null,
            campaigns: [],
            action_progress: { approved: 1 },
            outcomes: emptyOutcomes,
            lineage: emptyLineage,
            consequence:
                'Preparing creates draft campaigns for the exact approved actions. It does not launch or send email.',
        };
        const confirm = vi.spyOn(window, 'confirm');
        confirm.mockClear();
        const wrapper = mount(ExecutionPlanShow, {
            props: {
                plan: currentPlan,
                approval: { ...approval, state: 'approved', blockers: [] },
                execution,
                safety: {
                    creates_campaign: false,
                    grants_sending_authority: false,
                    compilation_sends_email: false,
                    available_actions: ['compile'],
                },
            },
            global: {
                stubs: {
                    AppWorkspace: {
                        props: ['area', 'title'],
                        template:
                            '<div><aside><slot name="sidebar" /></aside><slot /></div>',
                    },
                },
            },
        });

        expect(wrapper.text()).toContain('Prepare campaigns');
        expect(wrapper.text()).toContain('does not launch or send email');
        await wrapper.find('.execution-actions button').trigger('click');

        expect(confirm).not.toHaveBeenCalled();
        expect(routerPost).toHaveBeenCalledWith(
            '/execution-plans/plan-1/compile',
            { plan_id: 'plan-1', plan_hash: hash('d') },
            expect.objectContaining({
                headers: { 'Idempotency-Key': expect.any(String) },
            }),
        );
    });

    it('shows every compiled arm and confirms before launching them together', async () => {
        routerPost.mockClear();
        const currentPlan = { ...plan, status: 'compiled' };
        const wrapper = mount(ExecutionPlanShow, {
            props: {
                plan: currentPlan,
                approval: { ...approval, state: 'approved', blockers: [] },
                execution: {
                    is_owner: true,
                    state: 'compiled',
                    plan_id: currentPlan.id,
                    plan_hash: currentPlan.hashes.plan,
                    available_actions: ['launch'],
                    blockers: [],
                    latest_compilation: {
                        id: 'compilation-1',
                        attempt: 1,
                        state: 'succeeded',
                        safe_error_code: null,
                        safe_error_detail: null,
                        started_at: '2026-09-04T12:00:00Z',
                        finished_at: '2026-09-04T12:00:01Z',
                    },
                    campaigns: [
                        {
                            id: 41,
                            name: 'Control campaign',
                            status: 'draft',
                            arm_id: 'arm-1',
                            arm_label: 'Control',
                            href: '/campaigns/41',
                        },
                        {
                            id: 42,
                            name: 'Variant campaign',
                            status: 'draft',
                            arm_id: 'arm-2',
                            arm_label: 'Variant',
                            href: '/campaigns/42',
                        },
                    ],
                    action_progress: { compiled: 2 },
                    outcomes: emptyOutcomes,
                    lineage: emptyLineage,
                    consequence:
                        'Launching starts every experiment arm together.',
                },
                safety: {
                    creates_campaign: false,
                    grants_sending_authority: false,
                    compilation_sends_email: false,
                    available_actions: ['launch'],
                },
            },
            global: {
                stubs: {
                    AppWorkspace: {
                        props: ['area', 'title'],
                        template:
                            '<div><aside><slot name="sidebar" /></aside><slot /></div>',
                    },
                },
            },
        });

        expect(wrapper.text()).toContain('Control campaign');
        expect(wrapper.text()).toContain('Variant campaign');
        expect(
            wrapper
                .findAll('.campaign-links a')
                .map((link) => link.attributes('href')),
        ).toEqual(['/campaigns/41', '/campaigns/42']);
        await wrapper.find('.execution-actions button').trigger('click');

        expect(routerPost).not.toHaveBeenCalled();
        expect(wrapper.get('[role="alertdialog"]').text()).toContain(
            'Each message remains held until its approved scheduled window opens.',
        );
        await wrapper.get('[data-test="confirm-launch"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith(
            '/execution-plans/plan-1/launch',
            { plan_id: 'plan-1', plan_hash: hash('d') },
            expect.objectContaining({
                headers: { 'Idempotency-Key': expect.any(String) },
            }),
        );
    });

    it('links the bounded exposure, conversation, and conversion lineage', () => {
        const exposureId = 'exposure-1234567890';
        const wrapper = mount(ExecutionPlanShow, {
            props: {
                plan: { ...plan, status: 'executing' },
                approval: { ...approval, state: 'approved', blockers: [] },
                execution: {
                    is_owner: true,
                    state: 'executing',
                    plan_id: plan.id,
                    plan_hash: plan.hashes.plan,
                    available_actions: [],
                    blockers: [],
                    latest_compilation: null,
                    campaigns: [],
                    action_progress: { sent: 1 },
                    outcomes: {
                        exposures: {
                            total: 1,
                            provider_accepted: 1,
                            confirmed_sent: 0,
                            uncertain: 0,
                        },
                        conversations: { total: 1, replied: 1, stopped: 1 },
                        conversions: { total: 1 },
                        attributions: {
                            attributed: 1,
                            ambiguous: 0,
                            rejected: 0,
                            pending: 0,
                        },
                    },
                    lineage: {
                        exposures: [
                            {
                                id: exposureId,
                                action_id: 'action-1',
                                send_attempt_id: 17,
                                message_id: 23,
                                thread_id: 31,
                                source_event_id: 'provider-event-1',
                                boundary: 'provider_accepted',
                                occurred_at: '2026-09-04T12:00:00Z',
                            },
                        ],
                        conversations: [
                            {
                                id: 'conversation-1',
                                action_id: 'action-1',
                                exposure_id: exposureId,
                                thread_id: 31,
                                state: 'replied',
                                latest_event_at: '2026-09-04T12:04:00Z',
                            },
                        ],
                        conversions: [
                            {
                                event_id: 'conversion-event-123',
                                assessment_id: 'assessment-1',
                                assessment_state: 'attributed',
                                selected_exposure_id: exposureId,
                                method: 'deterministic_external_id',
                                reason_codes: ['same_account'],
                                assessed_at: '2026-09-04T12:05:00Z',
                            },
                        ],
                    },
                    consequence: 'Execution is underway.',
                },
                safety: {
                    creates_campaign: false,
                    grants_sending_authority: false,
                    compilation_sends_email: false,
                    available_actions: [],
                },
            },
            global: {
                stubs: {
                    AppWorkspace: {
                        props: ['area', 'title'],
                        template:
                            '<div><aside><slot name="sidebar" /></aside><slot /></div>',
                    },
                },
            },
        });

        expect(wrapper.get('[data-test="execution-lineage"]').text()).toContain(
            'Recent execution trail',
        );
        expect(
            wrapper.get('.lineage-group a[href="#action-action-1"]').text(),
        ).toContain('Action action-1');
        expect(
            wrapper.get('.lineage-group a[href="#lineage-thread-31"]').text(),
        ).toContain('Thread 31');
        expect(wrapper.get('#lineage-thread-31').text()).toContain('Replied');
        expect(
            wrapper.findAll(
                '.lineage-group a[href="#lineage-exposure-' + exposureId + '"]',
            ).length,
        ).toBe(2);
        expect(wrapper.text()).toContain('Deterministic External Id');
        expect(wrapper.text()).toContain('Same Account');
    });

    it('keeps preparation and launch controls owner-only', () => {
        const wrapper = mount(ExecutionPlanShow, {
            props: {
                plan: { ...plan, status: 'approved' },
                approval: { ...approval, state: 'approved', blockers: [] },
                execution: {
                    is_owner: false,
                    state: 'approved',
                    plan_id: plan.id,
                    plan_hash: plan.hashes.plan,
                    available_actions: [],
                    blockers: [],
                    latest_compilation: null,
                    campaigns: [],
                    action_progress: { approved: 1 },
                    outcomes: emptyOutcomes,
                    lineage: emptyLineage,
                    consequence:
                        'Preparing creates draft campaigns for the exact approved actions.',
                },
                safety: {
                    creates_campaign: false,
                    grants_sending_authority: false,
                    compilation_sends_email: false,
                    available_actions: [],
                },
            },
            global: {
                stubs: {
                    AppWorkspace: {
                        props: ['area', 'title'],
                        template:
                            '<div><aside><slot name="sidebar" /></aside><slot /></div>',
                    },
                },
            },
        });

        expect(wrapper.find('.execution-actions').exists()).toBe(false);
        expect(wrapper.text()).toContain(
            'Only the workspace owner can prepare or launch this plan.',
        );
    });
});
