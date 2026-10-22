import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { router, formPatch, clearErrors, pageState } = vi.hoisted(() => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
    formPatch: vi.fn(),
    clearErrors: vi.fn(),
    pageState: {
        flash: {} as Record<string, unknown>,
        errors: {} as Record<string, string>,
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
    usePage: () => ({ props: pageState }),
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        processing: false,
        isDirty: false,
        errors: {},
        patch: formPatch,
        clearErrors,
    }),
}));

import type { Composition } from '@/components/campaigns/CampaignCompositionForm.vue';
import CampaignShow from '@/pages/campaigns/Show.vue';

const validPerson = {
    id: 11,
    person_id: 4,
    name: 'Maya Chen',
    email: 'maya@example.test',
    company: 'Stripe',
    status: 'in_sequence',
    lifecycle: 'queued',
    mailbox: 'david@example.test',
    mailbox_id: 3,
    first_sent_at: null,
    thread_id: null,
    sequence_version: null,
};

const validStep = {
    id: 21,
    title: 'Intro',
    delay_minutes: 0,
    send_as: 'new_thread',
    subject: 'A question for {{company}}',
    body: 'Hi {{first_name}}, would this help {{company}}?',
};

const mailbox = {
    id: 3,
    email_address: 'david@example.test',
    status: 'connected',
    effective_reply_identity: {
        email: 'david@example.test',
        name: 'David',
    },
    sending_paused: false,
    daily_send_cap: 5,
    remaining_today: 5,
};

const emptyOutcomes = {
    queued: 1,
    sent: 0,
    replied: 0,
    interested: 0,
    bounced: 0,
    unsubscribed: 0,
    needs_review: 0,
};

const readyReadiness = {
    ready: true,
    blockers: [] as {
        id: string;
        section: 'audience' | 'sequence' | 'sending';
        label: string;
        message: string;
    }[],
};

const blockedReadiness = {
    ready: false,
    blockers: [
        {
            id: 'no_recipients',
            section: 'audience' as const,
            label: 'Audience',
            message: 'Add at least one recipient.',
        },
        {
            id: 'no_connected_mailbox',
            section: 'sending' as const,
            label: 'Sending',
            message: 'Select at least one connected mailbox.',
        },
    ],
};

const baseCampaign = {
    id: 7,
    name: 'Series A founders',
    status: 'draft' as const,
    send_mode: 'rotate',
    daily_send_limit: 5,
    people: [validPerson],
    steps: [validStep],
    mailboxes: [3],
    mailbox_emails: ['david@example.test'],
    timezone: 'UTC',
    send_window_start: '09:00:00',
    send_window_end: '17:00:00',
    business_days_only: true,
    open_tracking_enabled: false,
    updated_at: '2026-08-22T10:00:00Z',
    launched_at: null,
    editable: true,
    has_draft: false,
    sequence_issues: [] as { code: string; message: string }[],
    supported_variables: ['first_name', 'company'],
    sequence: {
        draft_version: 1,
        published_version: null as number | null,
        versions_count: 1,
        unstarted_count: 0,
    },
};

const stubs = {
    CampaignCompositionForm: {
        methods: { ensureSaved: async () => true },
        template:
            '<div><select id="campaign-goal" /><select id="campaign-offer" /><select id="campaign-scope" /><select id="campaign-finish-mode" /></div>',
    },
    AppWorkspace: {
        template:
            '<div><div class="top"><slot name="actions" /></div><aside><slot name="sidebar" /></aside><slot /></div>',
    },
    CampaignEnrollmentDialog: {
        name: 'CampaignEnrollmentDialog',
        template: '<div class="enrollment-picker">Registry picker</div>',
        methods: {
            focusSearch: () => undefined,
        },
    },
};

function mountShow(
    overrides: {
        campaign?: Partial<typeof baseCampaign>;
        readiness?: typeof readyReadiness;
        outcomes?: typeof emptyOutcomes;
        availableMailboxes?: (typeof mailbox)[];
        composition?: Composition;
    } = {},
) {
    return mount(CampaignShow, {
        props: {
            campaign: { ...baseCampaign, ...overrides.campaign },
            readiness: overrides.readiness ?? readyReadiness,
            outcomes: overrides.outcomes ?? emptyOutcomes,
            availableMailboxes: overrides.availableMailboxes ?? [mailbox],
            composition: overrides.composition,
        },
        global: { stubs },
    });
}

describe('Campaign detail', () => {
    it('guides a saved audience through Sequence and Sending to review', async () => {
        const wrapper = mountShow();
        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');
        const progress = () => wrapper.get('[aria-label="Setup next step"]');
        expect(progress().text()).toContain('Step 2 of 4');
        expect(progress().text()).toContain('Continue to Sequence');
        await progress().get('button').trigger('click');
        expect(progress().text()).toContain('Step 3 of 4');
        await progress().get('button').trigger('click');
        expect(progress().text()).toContain('Step 4 of 4');
        expect(progress().text()).toContain('Review exact messages');
    });

    it('explains an empty audience and offers enrollment instead of continuing', async () => {
        const wrapper = mountShow({
            campaign: { people: [] },
            readiness: {
                ...readyReadiness,
                ready: false,
                blockers: [
                    {
                        section: 'audience',
                        label: 'Audience',
                        message: 'Add at least one eligible person.',
                    },
                ],
            },
        });
        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');
        const progress = wrapper.get('[aria-label="Setup next step"]');
        expect(progress.text()).toContain('Add at least one eligible person.');
        expect(progress.get('button').text()).toBe('Add people');
        await progress.get('button').trigger('click');
        expect(wrapper.find('.campaign-modal').exists()).toBe(true);
    });
    it('keeps unsupported controls out of a simple human email editor', async () => {
        const wrapper = mountShow({
            composition: {
                revision: 1,
                editable: true,
                goals: [],
                offers: [],
                objective_goal_version_id: null,
                offer_version_id: null,
            },
        });
        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');
        expect(wrapper.text()).not.toContain('Add follow-up');
        expect(wrapper.find('.sequence-settings-row').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Subject variations');
        expect(wrapper.find('textarea').exists()).toBe(true);
    });
    it('keeps unsaved campaign selections on Overview instead of discarding them during review', async () => {
        const wrapper = mountShow({
            composition: {
                revision: 1,
                editable: true,
                goals: [],
                offers: [],
                objective_goal_version_id: 'goal',
                offer_version_id: 'offer',
            },
        });
        wrapper.findComponent(stubs.CampaignCompositionForm).vm.ensureSaved =
            async () => false;
        await wrapper.get('.top button').trigger('click');
        await flushPromises();
        expect(router.visit).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain(
            'Check the save status and retry before leaving Overview',
        );
    });
    it('keeps unsaved sending settings out of exact review and explains how to proceed', async () => {
        const wrapper = mountShow({
            composition: {
                revision: 1,
                editable: true,
                goals: [],
                offers: [],
                objective_goal_version_id: 'goal',
                offer_version_id: 'offer',
            },
        });
        (
            wrapper.vm.$.setupState as unknown as {
                sending: { isDirty: boolean };
            }
        ).sending.isDirty = true;
        await wrapper.get('.top button').trigger('click');
        expect(router.visit).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain(
            'Save sending to apply inbox choices and calculate the campaign capacity before review.',
        );
    });

    it('does not restart setup or offer resume when every recipient has finished', () => {
        const wrapper = mountShow({
            campaign: { status: 'completed', people: [] },
        });
        expect(wrapper.find('.campaign-readiness').exists()).toBe(false);
        expect(wrapper.get('.top').text()).not.toContain('Resume campaign');
        expect(wrapper.get('.campaign-next-step').text()).toContain(
            'Review outcomes',
        );
        expect(
            wrapper.get('.campaign-next-step a').attributes('href'),
        ).toContain('/unibox?folder=all_replies');
    });

    it('opens the incomplete Objective even after its selection was saved', async () => {
        const wrapper = mountShow({
            composition: {
                revision: 2,
                editable: true,
                objective_goal_version_id: 'goal-1',
                offer_version_id: 'offer-1',
                execution_authority_scope_id: 'scope-1',
                offers: [],
                goals: [
                    {
                        id: 'goal-1',
                        objective_id: 'objective-1',
                        name: 'Meetings',
                        version: 1,
                        target_count: null,
                        measurement: 'meeting_booked',
                        deadline: null,
                        current: true,
                        measurement_configured: false,
                    },
                ],
            },
        });
        expect(wrapper.get('.campaign-next-step').text()).toContain(
            'What counts',
        );
        expect(wrapper.get('.campaign-next-step button').text()).toBe(
            'Complete Objective measurement',
        );
        await wrapper.get('.campaign-next-step button').trigger('click');
        expect(router.visit).toHaveBeenCalledWith(
            `/objectives/objective-1?from_campaign=${baseCampaign.id}`,
        );
    });

    it.each([
        [null, null, null, '#campaign-goal', 'Choose Objective and Offer'],
        ['goal-1', null, null, '#campaign-offer', 'Choose Objective and Offer'],
        ['goal-1', 'offer-1', null, '#campaign-scope', 'Save and continue'],
    ])(
        'focuses the missing setup field instead of reopening Overview (%s, %s, %s)',
        async (goal, offer, scope, selector, label) => {
            const wrapper = mountShow({
                composition: {
                    revision: 2,
                    editable: true,
                    goals: [],
                    offers: [],
                    objective_goal_version_id: goal,
                    offer_version_id: offer,
                    execution_authority_scope_id: scope,
                },
            });
            const focus = vi.spyOn(HTMLElement.prototype, 'focus');
            expect(wrapper.get('.campaign-next-step button').text()).toBe(
                label,
            );
            await wrapper.get('.campaign-next-step button').trigger('click');
            await flushPromises();
            expect(focus).toHaveBeenCalled();
            expect(
                wrapper
                    .findComponent(stubs.CampaignCompositionForm)
                    .attributes('setup-feedback'),
            ).toBe(
                scope === null && goal && offer ? 'schedule' : 'composition',
            );
            focus.mockRestore();
        },
    );

    it('routes human approval readiness to exact review rather than mailbox settings', async () => {
        const wrapper = mountShow({
            composition: {
                execution_authority_scope_id: 'scope-1',
                revision: 1,
                objective_goal_version_id: 'goal-1',
                offer_version_id: 'offer-1',
                editable: true,
                goals: [],
                offers: [],
            },
            readiness: {
                ready: false,
                blockers: [
                    {
                        id: 'governed_action_missing',
                        section: 'sending',
                        label: 'Approval',
                        message: 'Exact approval required.',
                    },
                ],
            },
        });
        expect(wrapper.get('.campaign-next-step h2').text()).toBe(
            'Exact review and approval',
        );
        await wrapper.get('.campaign-next-step button').trigger('click');
        expect(router.visit).toHaveBeenCalledWith(
            `/campaigns/${baseCampaign.id}/review?prepare=1`,
        );
        expect(wrapper.get('.top').text()).toContain('Review exact messages');
        expect(wrapper.get('.top').text()).not.toContain('Review launch');
    });
    it('includes saved Objective and Offer selections in the human setup checklist', async () => {
        const wrapper = mountShow({
            composition: {
                revision: 0,
                objective_goal_version_id: null,
                offer_version_id: null,
                editable: true,
                goals: [],
                offers: [],
            },
        });
        expect(wrapper.get('.campaign-readiness').text()).toContain(
            'Choose or create an Objective and an Offer',
        );
        expect(wrapper.get('.campaign-next-step h2').text()).toBe(
            'Objective and Offer',
        );
        expect(wrapper.get('.campaign-readiness').text()).toContain(
            'email saved — review before sending',
        );
        expect(wrapper.get('.campaign-readiness').text()).not.toContain(
            'email reviewed',
        );
        await wrapper.setProps({
            composition: {
                revision: 1,
                objective_goal_version_id: 'goal-1',
                offer_version_id: 'offer-1',
                editable: true,
                goals: [],
                offers: [],
            },
        });
        expect(wrapper.get('.campaign-readiness').text()).toContain(
            'Selections saved.',
        );
    });

    beforeEach(() => {
        window.history.replaceState({}, '', '/campaigns/1');
        router.visit.mockReset();
        router.post.mockReset();
        router.patch.mockReset();
        router.put.mockReset();
        router.delete.mockReset();
        formPatch.mockReset();
        clearErrors.mockReset();
        pageState.flash = {};
        pageState.errors = {};
    });

    it('opens on an operational overview with a complete launch checklist', () => {
        const wrapper = mountShow();

        expect(wrapper.get('.campaign-title-line').text()).toContain('Draft');
        expect(wrapper.get('.campaign-readiness').text()).toContain(
            'Ready for review',
        );
        expect(wrapper.findAll('.campaign-check')).toHaveLength(3);
        expect(
            wrapper
                .findAll('.campaign-check-icon')
                .every((icon) => icon.classes().includes('ready')),
        ).toBe(true);
        expect(wrapper.text()).not.toContain('Enable');
        expect(wrapper.get('.campaign-summary-strip').text()).toContain(
            '1 queued',
        );
    });

    it('shows live inbox-derived capacity and keeps suggested inboxes unsaved', async () => {
        const secondMailbox = {
            ...mailbox,
            id: 4,
            email_address: 'maya@example.test',
            effective_reply_identity: {
                email: 'maya@example.test',
                name: 'Maya',
            },
            daily_send_cap: 7,
            remaining_today: 2,
        };
        const wrapper = mountShow({
            campaign: { daily_send_limit: 10 },
            availableMailboxes: [mailbox, secondMailbox],
            composition: {
                revision: 1,
                editable: true,
                goals: [],
                offers: [],
                objective_goal_version_id: null,
                offer_version_id: null,
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');

        expect(
            wrapper.get('[aria-label="Daily sending capacity"]').text(),
        ).toContain('1 ready inbox · 5/day');
        expect(
            wrapper.get('[aria-label="Daily sending capacity"]').text(),
        ).toContain('The target is 5 messages/day above');
        await wrapper
            .findAll('.sending-capacity-actions button')
            .find((button) => button.text().includes('Use 1 ready inbox'))!
            .trigger('click');

        const formState = wrapper.vm.$.setupState as unknown as {
            sending: { mailbox_ids: number[] };
        };
        expect(formState.sending.mailbox_ids).toEqual([3, 4]);
        expect(formPatch).not.toHaveBeenCalled();
    });

    it('offers a one-click lower target remedy when inbox capacity is short', async () => {
        const wrapper = mountShow({
            campaign: { daily_send_limit: 12 },
            composition: {
                revision: 1,
                editable: true,
                goals: [],
                offers: [],
                objective_goal_version_id: null,
                offer_version_id: null,
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');
        await wrapper
            .findAll('.sending-capacity-actions button')
            .find((button) => button.text().includes('Lower target'))!
            .trigger('click');

        const formState = wrapper.vm.$.setupState as unknown as {
            sending: { daily_send_limit: number };
        };
        expect(formState.sending.daily_send_limit).toBe(5);
    });

    it('makes Save and continue prepare automatic allowance even when no field is dirty', async () => {
        const wrapper = mountShow({
            composition: {
                revision: 1,
                editable: true,
                goals: [],
                offers: [],
                objective_goal_version_id: 'goal',
                offer_version_id: 'offer',
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');
        const save = wrapper
            .findAll('.sending-actions button')
            .find((button) => button.text().includes('Save and continue'))!;
        expect(save.attributes('disabled')).toBeUndefined();
        await save.trigger('click');

        const formState = wrapper.vm.$.setupState as unknown as {
            sending: { automatic_allowance: boolean };
        };
        expect(formState.sending.automatic_allowance).toBe(true);
        expect(formPatch).toHaveBeenCalledWith(
            '/campaigns/7',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('renders server-owned outcome counts on the overview strip', () => {
        const wrapper = mountShow({
            outcomes: {
                queued: 2,
                sent: 8,
                replied: 5,
                interested: 3,
                bounced: 1,
                unsubscribed: 2,
                needs_review: 0,
            },
        });

        const strip = wrapper.get('.campaign-summary-strip').text();
        expect(strip).toContain('2 queued');
        expect(strip).toContain('5');
        expect(strip).toContain('3 interested');
        expect(strip).toContain('1');
        expect(strip).toContain('2 unsubscribed');
    });

    it('shows a manual-review banner when sends need review', () => {
        const wrapper = mountShow({
            outcomes: {
                ...emptyOutcomes,
                needs_review: 2,
            },
        });

        expect(wrapper.get('.campaign-review-banner').text()).toContain(
            '2 sends need manual review',
        );
        expect(wrapper.get('.campaign-review-banner').text()).toContain(
            'docs/operations.md',
        );
    });

    it('names blockers from readiness and deep-links to the section', async () => {
        const wrapper = mountShow({
            campaign: {
                people: [],
                mailboxes: [],
                mailbox_emails: [],
            },
            readiness: blockedReadiness,
        });

        expect(wrapper.get('.campaign-readiness').text()).toContain(
            'Finish campaign setup',
        );
        expect(wrapper.get('.campaign-readiness').text()).toContain(
            'Add at least one recipient',
        );
        expect(wrapper.get('.campaign-readiness').text()).toContain(
            'Select at least one connected mailbox',
        );

        await wrapper
            .findAll('.campaign-check')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');
        expect(wrapper.text()).toContain('Add the campaign audience');

        await wrapper.get('.top .btn').trigger('click');
        const launch = wrapper
            .findAll('.launch-review .btn')
            .find((button) => button.text() === 'Launch to 0 people')!;

        expect(launch.attributes('disabled')).toBeDefined();
    });

    it('renders a server-provided sequence issue in the launch checklist', () => {
        const wrapper = mountShow({
            campaign: {
                sequence_issues: [
                    {
                        code: 'missing_body',
                        message: 'Every step needs a body.',
                    },
                ],
            },
            readiness: {
                ready: false,
                blockers: [
                    {
                        id: 'step_missing_body',
                        section: 'sequence',
                        label: 'Sequence',
                        message: 'Every step needs a body.',
                    },
                ],
            },
        });

        expect(wrapper.get('.campaign-readiness').text()).toContain(
            'Every step needs a body.',
        );
    });

    it('launches only after the review passes', async () => {
        const wrapper = mount(CampaignShow, {
            props: {
                campaign: baseCampaign,
                readiness: readyReadiness,
                outcomes: emptyOutcomes,
                availableMailboxes: [mailbox],
            },
            global: { stubs },
            attachTo: document.body,
        });

        const trigger = wrapper.get('.top .btn');
        await trigger.trigger('click');
        await flushPromises();
        const dialogButtons = wrapper.findAll('.launch-review button');
        const launchButton = wrapper
            .findAll('.launch-review .btn')
            .find((button) => button.text() === 'Launch to 1 person')!;

        launchButton.element.focus();
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }),
        );
        expect(document.activeElement).toBe(dialogButtons[0].element);

        await launchButton.trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/campaigns/7/launch',
            {},
            expect.objectContaining({
                onSuccess: expect.any(Function),
                onFinish: expect.any(Function),
            }),
        );
        wrapper.unmount();
    });

    it('keeps individually paused recipients out of the resume count', async () => {
        const wrapper = mountShow({
            campaign: {
                status: 'paused',
                people: [
                    validPerson,
                    {
                        ...validPerson,
                        id: 12,
                        person_id: 5,
                        email: 'paused@example.test',
                        lifecycle: 'paused',
                    },
                ],
            },
        });

        await wrapper.get('.top .btn').trigger('click');

        expect(
            wrapper
                .findAll('.launch-review .btn')
                .some((button) => button.text() === 'Resume campaign'),
        ).toBe(true);
        expect(wrapper.get('.launch-review').text()).toContain(
            '1 person ready to receive',
        );
        expect(wrapper.get('.launch-review').text()).toContain(
            '1 excluded by status or eligibility',
        );
    });

    it('renames the campaign from the title control', async () => {
        const wrapper = mountShow();

        await wrapper.get('.campaign-rename').trigger('click');
        await flushPromises();
        expect(wrapper.get('#rename-title').text()).toContain(
            'Rename campaign',
        );

        await wrapper.get('[role="dialog"] input').setValue('Renamed founders');
        await wrapper.get('[role="dialog"]').trigger('submit');

        expect(formPatch).toHaveBeenCalledWith(
            '/campaigns/7',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
            }),
        );
    });

    it('shows audience controls while the campaign is active', async () => {
        const wrapper = mountShow({
            campaign: {
                status: 'active',
                editable: false,
                launched_at: '2026-08-22T10:00:00Z',
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');

        expect(wrapper.find('.row-menu').exists()).toBe(true);
        expect(
            wrapper
                .findAll('button')
                .some((button) => button.text().includes('Add people')),
        ).toBe(true);
        expect(wrapper.get('.top').text()).toContain('Pause sending');
        expect(wrapper.get('.top').text()).toContain('Mark complete');
    });

    it('hides audience controls when the campaign is completed', async () => {
        const wrapper = mountShow({
            campaign: {
                status: 'completed',
                editable: false,
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');

        expect(wrapper.find('.row-menu').exists()).toBe(false);
        expect(wrapper.get('.top').text()).toContain('Resume campaign');
    });

    it('confirms completion in a custom modal', async () => {
        const wrapper = mountShow({
            campaign: {
                status: 'active',
                editable: false,
                launched_at: '2026-08-22T10:00:00Z',
            },
        });

        await wrapper
            .findAll('.top .btn')
            .find((button) => button.text() === 'Mark complete')!
            .trigger('click');
        await flushPromises();

        expect(wrapper.get('#campaign-confirm-title').text()).toContain(
            'Mark campaign complete?',
        );
        expect(wrapper.text()).toContain('Completing stops all future sends');
        expect(router.post).not.toHaveBeenCalled();

        await wrapper
            .get('[data-test="campaign-confirm-accept"]')
            .trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/campaigns/7/complete',
            {},
            expect.objectContaining({
                onSuccess: expect.any(Function),
                onFinish: expect.any(Function),
            }),
        );
    });

    it('uses singular person wording for a one-person audience', async () => {
        const wrapper = mountShow({
            campaign: {
                people: [validPerson],
            },
        });

        expect(wrapper.get('.campaign-readiness').text()).toContain(
            '1 person ready',
        );

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');

        expect(wrapper.get('.campaign-section-heading h2').text()).toBe(
            '1 person',
        );
    });

    it('filters the audience by meaningful lifecycle groups', async () => {
        const wrapper = mountShow({
            campaign: {
                people: [
                    validPerson,
                    {
                        ...validPerson,
                        id: 12,
                        name: 'Priya Shah',
                        email: 'priya@example.test',
                        lifecycle: 'interested',
                    },
                    {
                        ...validPerson,
                        id: 13,
                        name: 'Sam Rivera',
                        email: 'sam@example.test',
                        lifecycle: 'bounced',
                    },
                ],
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');
        await wrapper
            .findAll('.campaign-filter-bar button')
            .find((button) => button.text().includes('Replied'))!
            .trigger('click');

        expect(wrapper.findAll('.campaign-person-row')).toHaveLength(1);
        expect(wrapper.get('.campaign-person-row').text()).toContain(
            'Priya Shah',
        );
    });

    it('renders a per-person sequence preview', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    subject: 'A question for Stripe',
                    body: 'Hi Maya, would this help Stripe?',
                    to: 'maya@example.test',
                    recipient_id: 11,
                }),
            }),
        );
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');
        await wrapper
            .findAll('.campaign-segmented button')
            .find((button) => button.text() === 'Preview')!
            .trigger('click');
        await flushPromises();

        expect(wrapper.get('.sequence-preview').text()).toContain(
            'A question for Stripe',
        );
        expect(wrapper.get('.sequence-preview-body').text()).toContain(
            'Hi Maya, would this help Stripe?',
        );
        vi.unstubAllGlobals();
    });

    it('switches from sample data to the first recipient added after page load', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                subject: 'A question for Stripe',
                body: 'Hi Maya, would this help Stripe?',
                to: 'maya@example.test',
                recipient_id: 11,
            }),
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountShow({ campaign: { people: [] } });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');
        await wrapper
            .findAll('.campaign-segmented button')
            .find((button) => button.text() === 'Preview')!
            .trigger('click');
        await flushPromises();

        await wrapper.setProps({
            campaign: { ...baseCampaign, people: [validPerson] },
        });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/sequence-steps/21/preview?recipient_id=11',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        vi.unstubAllGlobals();
    });

    it('shows sequence version labels and a per-person version tag', async () => {
        const wrapper = mountShow({
            campaign: {
                status: 'paused',
                has_draft: true,
                people: [
                    {
                        ...validPerson,
                        sequence_version: 1,
                    },
                ],
                sequence: {
                    draft_version: 2,
                    published_version: 1,
                    versions_count: 2,
                    unstarted_count: 4,
                },
            },
        });

        expect(wrapper.text()).toContain(
            'You are editing draft v2. Recipients who already received emails stay on v1; 4 recipients who have not started will move to v2 when you resume.',
        );

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');

        expect(wrapper.get('[data-test="sequence-version-line"]').text()).toBe(
            'Editing draft v2 — v1 is live',
        );

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');

        expect(wrapper.get('.sequence-version-tag').text()).toBe('v1');
    });

    it('saves title, subject and body together and retains text after validation failure', async () => {
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');

        await wrapper.get('[aria-label="Step title"]').setValue('Opener');
        await wrapper.get('[aria-label="Step title"]').trigger('change');
        await wrapper
            .get('.sequence-fields input:not([aria-label])')
            .setValue('A new subject');
        await wrapper.get('.sequence-fields textarea').setValue('A new body');
        expect(router.patch).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Unsaved email changes');
        await wrapper.get('.sequence-fields').trigger('submit');

        expect(router.patch).toHaveBeenCalledWith(
            '/sequence-steps/21',
            {
                title: 'Opener',
                subject_template: 'A new subject',
                body_template: 'A new body',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        const callbacks = router.patch.mock.calls[0][2];
        await wrapper.get('.sequence-fields').trigger('submit');
        expect(router.patch).toHaveBeenCalledTimes(1);
        callbacks.onError({
            body_template: 'Unknown variable. Correct your email.',
        });
        callbacks.onFinish();
        await flushPromises();
        expect(wrapper.text()).toContain(
            'Unknown variable. Correct your email.',
        );
        expect(
            (
                wrapper.get('.sequence-fields textarea')
                    .element as HTMLTextAreaElement
            ).value,
        ).toBe('A new body');
        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');
        expect(wrapper.find('.sequence-fields').exists()).toBe(true);
        expect(wrapper.text()).toContain('Save email before leaving Sequence');
    });

    it('shows save-time sequence field errors', async () => {
        pageState.errors = {
            body_template:
                'Unknown variable {{last_name}}. Supported: {{first_name}}, {{company}}.',
        };
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');

        expect(wrapper.get('[data-test="sequence-body-error"]').text()).toBe(
            'Unknown variable {{last_name}}. Supported: {{first_name}}, {{company}}.',
        );
    });

    it('shows rendering errors beside the affected sequence field', async () => {
        pageState.errors = {
            subject_template:
                'Email 1 subject: Unclosed variation. Add a closing } or escape the opening brace as \\{.',
        };
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');

        expect(wrapper.get('[data-test="sequence-subject-error"]').text()).toBe(
            'Email 1 subject: Unclosed variation. Add a closing } or escape the opening brace as \\{.',
        );
    });

    it('explains stable per-recipient variations in the sequence editor', async () => {
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');

        expect(wrapper.get('[data-test="sequence-spintax-help"]').text()).toBe(
            'Add variations with {Hi|Hello|Hey}. Outboss keeps one choice stable for each recipient.',
        );
        const toggle = wrapper
            .findAll('label')
            .find((label) => label.text() === 'Body variations')!
            .get('input');
        await toggle.setValue(true);
        expect(router.patch).toHaveBeenCalledWith(
            '/sequence-steps/21',
            { body_spintax_enabled: true },
            expect.any(Object),
        );
    });

    it('reorders sequence steps with the expected payload', async () => {
        const followUp = {
            ...validStep,
            id: 22,
            title: 'Follow-up',
            delay_minutes: 4320,
            send_as: 'reply',
            subject: null,
            body: 'Bumping this once.',
        };
        const wrapper = mountShow({
            campaign: {
                steps: [validStep, followUp],
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');

        const upButtons = wrapper.findAll('[aria-label="Move email up"]');
        const downButtons = wrapper.findAll('[aria-label="Move email down"]');

        expect(upButtons).toHaveLength(2);
        expect(downButtons).toHaveLength(2);
        expect(upButtons[0].attributes('disabled')).toBeDefined();
        expect(downButtons[1].attributes('disabled')).toBeDefined();
        expect(upButtons[1].attributes('disabled')).toBeUndefined();

        await downButtons[0].trigger('click');

        expect(router.put).toHaveBeenCalledWith(
            '/campaigns/7/steps/order',
            { step_ids: [22, 21] },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows test errors and prevents duplicate test requests while pending', async () => {
        const wrapper = mountShow();
        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');
        const address = wrapper.get('[aria-label="Test address"]');
        const send = wrapper.get('.sequence-test-row button');
        await address.setValue('not-an-email');
        await send.trigger('click');
        expect(router.post).not.toHaveBeenCalled();
        expect(wrapper.get('#test-send-error').text()).toContain(
            'valid email address',
        );
        await address.setValue('qa@example.test');
        await send.trigger('click');
        await send.trigger('click');
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(send.text()).toBe('Sending test…');
        const options = router.post.mock.calls[0][2];
        options.onError({ to: 'This test recipient is suppressed.' });
        options.onFinish();
        await flushPromises();
        expect(wrapper.get('#test-send-error').text()).toBe(
            'This test recipient is suppressed.',
        );
        expect(send.attributes('disabled')).toBeUndefined();
    });

    it('does not offer an unusable mailbox for test sends', async () => {
        const unavailableMailbox = {
            ...mailbox,
            id: 4,
            status: 'disconnected',
            effective_reply_identity: null,
        };
        const wrapper = mountShow({
            availableMailboxes: [unavailableMailbox],
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sequence'))!
            .trigger('click');
        await wrapper
            .get('[aria-label="Test address"]')
            .setValue('me@test.dev');

        expect(
            wrapper
                .get('[aria-label="Test mailbox"] option')
                .attributes('disabled'),
        ).toBeDefined();
        expect(
            wrapper
                .findAll('.sequence-test-row button')
                .find((button) => button.text() === 'Send test')!
                .attributes('disabled'),
        ).toBeDefined();
    });

    it('opens a two-mode add people dialog defaulting to the registry', async () => {
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Add people'))!
            .trigger('click');

        expect(wrapper.get('.enrollment-picker').text()).toContain(
            'Registry picker',
        );
        expect(wrapper.text()).toContain('From registry');
        expect(wrapper.text()).toContain('Paste / CSV');
        expect(wrapper.text()).toContain('Upload CSV');
        expect(wrapper.find('#campaign-import-shortcut-file').exists()).toBe(
            true,
        );

        await wrapper
            .findAll('[role="tab"]')
            .find((button) => button.text() === 'Paste / CSV')!
            .trigger('click');

        expect(wrapper.find('#paste-people').exists()).toBe(true);

        await wrapper
            .get('#paste-people')
            .setValue(
                'Email Address,Full Name,Company\nmaya@acme.test,Maya Chen,Acme',
            );
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Continue')!
            .trigger('click');

        expect(
            wrapper.get('#campaign-import-map-email').element,
        ).toHaveProperty('value', 'Email Address');
        expect(wrapper.get('#campaign-import-map-name').element).toHaveProperty(
            'value',
            'Full Name',
        );
        expect(
            wrapper.get('#campaign-import-map-company').element,
        ).toHaveProperty('value', 'Company');
        expect(wrapper.text()).toContain('{{first_name}}');
        expect(wrapper.text()).toContain('{{company}}');
    });

    it('keeps the reviewed counts visible when committing an import fails', async () => {
        pageState.flash = {
            import: {
                id: 9,
                mapping_version: 1,
                ready: 74,
                invalid: 0,
                duplicate: 0,
                suppressed: 0,
            },
        };
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Add people'))!
            .trigger('click');
        await wrapper
            .findAll('[role="tab"]')
            .find((button) => button.text() === 'Paste / CSV')!
            .trigger('click');
        await wrapper
            .get('#paste-people')
            .setValue('email,name\nmaya@acme.test,Maya');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Continue')!
            .trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Review import')!
            .trigger('click');

        router.post.mock.calls[0][2].onSuccess();
        router.post.mock.calls[0][2].onFinish?.();
        await flushPromises();
        expect(wrapper.get('.import-results').text()).toContain('74');

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Add ready people')!
            .trigger('click');
        router.post.mock.calls[1][2].onError({
            import: 'Assign a mailbox before importing recipients.',
        });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain(
            'Assign a mailbox before importing recipients.',
        );
        expect(wrapper.get('.import-results').text()).toContain('74');
    });

    it('shows nested CSV row validation errors on the mapping screen', async () => {
        pageState.errors = {
            'rows.0.Company': 'The first company value is too long.',
        };
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Audience'))!
            .trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Add people'))!
            .trigger('click');
        await wrapper
            .findAll('[role="tab"]')
            .find((button) => button.text() === 'Paste / CSV')!
            .trigger('click');
        await wrapper
            .get('#paste-people')
            .setValue('email,company\nmaya@acme.test,Acme');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Continue')!
            .trigger('click');

        expect(wrapper.get('[role="alert"]').text()).toBe(
            'The first company value is too long.',
        );
    });

    it('renders the open-tracking toggle as editable on drafts', async () => {
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');

        const toggle = wrapper.get('[data-test="open-tracking-toggle"]');
        expect((toggle.element as HTMLInputElement).checked).toBe(false);
        expect(toggle.attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain(
            'Adds an invisible image to detect opens',
        );
        expect(wrapper.text()).toContain('SPF, DKIM, and DMARC');
    });

    it('disables the open-tracking toggle when the campaign is not editable', async () => {
        const wrapper = mountShow({
            campaign: {
                status: 'active',
                editable: false,
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');

        expect(
            wrapper
                .get('[data-test="open-tracking-toggle"]')
                .attributes('disabled'),
        ).toBeDefined();
    });

    it('shows sender identities without hiding them behind a collapsed row', async () => {
        const wrapper = mountShow();

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');

        const identities = wrapper.get('[data-test="sending-identities"]');
        expect(identities.text()).toContain('Who will send this campaign?');
        expect(identities.text()).toContain('david@example.test');
        expect(identities.get('.sending-mailboxes input').attributes('type')).toBe(
            'checkbox',
        );
        expect(wrapper.text()).not.toContain('Selected inboxes:');
        expect(wrapper.find('.inbox-selection summary').exists()).toBe(false);
    });

    it('keeps the sender list visible when no inbox is selected yet', async () => {
        const wrapper = mountShow({
            campaign: {
                mailboxes: [],
                mailbox_emails: [],
            },
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');

        const checkbox = wrapper.get(
            '[data-test="sending-identities"] .sending-mailboxes input',
        );
        expect((checkbox.element as HTMLInputElement).checked).toBe(false);
        expect(wrapper.text()).toContain('Use 1 ready inbox');
    });

    it('lets an unavailable attached mailbox be removed from sending', async () => {
        const unavailableMailbox = {
            ...mailbox,
            id: 4,
            status: 'disconnected',
            effective_reply_identity: null,
        };
        const wrapper = mountShow({
            campaign: {
                mailboxes: [4],
                mailbox_emails: ['old@example.test'],
            },
            availableMailboxes: [unavailableMailbox],
        });

        await wrapper
            .findAll('.campaign-section-nav button')
            .find((button) => button.text().includes('Sending'))!
            .trigger('click');

        const checkbox = wrapper.get('.sending-mailboxes input');
        expect(checkbox.attributes('disabled')).toBeUndefined();
        expect((checkbox.element as HTMLInputElement).checked).toBe(true);
    });
});
