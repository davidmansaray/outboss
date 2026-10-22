import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
const post = vi.hoisted(() => vi.fn());
const transform = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            post,
            transform,
            clearErrors: vi.fn(),
        }),
}));
import CampaignCompositionForm from '@/components/campaigns/CampaignCompositionForm.vue';
const composition = {
    revision: 1,
    objective_goal_version_id: 'goal-v1',
    offer_version_id: 'offer-v2',
    editable: true,
    goals: [
        {
            id: 'goal-v1',
            objective_id: 'goal',
            name: 'Meetings',
            version: 1,
            target_count: 10,
            measurement: 'meeting_booked',
            deadline: null,
            current: false,
        },
    ],
    offers: [
        {
            id: 'offer-v2',
            offer_id: 'offer',
            name: 'Consultation',
            version: 2,
            status: 'draft',
            current: true,
        },
    ],
};
describe('Campaign composition', () => {
    it('autosaves once, waits for confirmation, and keeps failed edits available for retry', async () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: { campaignId: 1, composition },
        });
        expect(wrapper.text()).not.toContain('Save selections');
        await wrapper.get('#campaign-target').setValue('4');
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('Saving…');
        let settled = false;
        const waiting = wrapper.vm.ensureSaved().then((value: boolean) => {
            settled = true;

            return value;
        });
        await flushPromises();
        expect(settled).toBe(false);
        expect(post).toHaveBeenCalledTimes(1);
        post.mock.calls[0][1].onError();
        post.mock.calls[0][1].onFinish();
        expect(await waiting).toBe(false);
        await flushPromises();
        expect(wrapper.text()).toContain('Not saved');
        expect(
            (wrapper.get('#campaign-target').element as HTMLInputElement).value,
        ).toBe('4');
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Retry save')!
            .trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(2);
        post.mock.calls[1][1].onSuccess();
        post.mock.calls[1][1].onFinish();
        await flushPromises();
        expect(wrapper.text()).toContain('Saved — Objective: Meetings (v1)');
        expect(await wrapper.vm.ensureSaved()).toBe(true);
        expect(post).toHaveBeenCalledTimes(2);
    });
    it('does not save an enabled deadline until a date is supplied', async () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: { campaignId: 1, composition },
        });
        await wrapper.get('#campaign-finish-mode').setValue('true');
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
        expect(await wrapper.vm.ensureSaved()).toBe(false);
        expect(wrapper.text()).toContain(
            'enter the selected start or deadline date',
        );
        await wrapper.get('#campaign-end').setValue('2026-09-16T17:00');
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);
    });
    it.each(['draft', 'retired'])(
        'explains why a saved %s Offer cannot continue',
        (status) => {
            const wrapper = mount(CampaignCompositionForm, {
                props: {
                    campaignId: 1,
                    composition: {
                        ...composition,
                        offers: [{ ...composition.offers[0], status }],
                    },
                    setupFeedback: 'composition',
                },
            });
            expect(wrapper.get('#campaign-offer-feedback').text()).toContain(
                'current active version',
            );
        },
    );
    it('explains unavailable Objectives without changing their saved selection', () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: {
                campaignId: 1,
                composition: {
                    ...composition,
                    goals: [{ ...composition.goals[0], status: 'paused' }],
                },
                setupFeedback: 'composition',
            },
        });
        expect(wrapper.get('#campaign-goal-feedback').text()).toContain(
            'not available for a new campaign',
        );
        expect(
            (wrapper.get('#campaign-goal').element as HTMLSelectElement).value,
        ).toBe('goal-v1');
    });
    it('explains missing and unsaved Offer selections without blocking draft saves', async () => {
        const readyComposition = {
            ...composition,
            offers: [{ ...composition.offers[0], status: 'active' }],
        };
        const wrapper = mount(CampaignCompositionForm, {
            props: {
                campaignId: 8,
                composition: { ...readyComposition, offer_version_id: null },
                setupFeedback: 'composition',
            },
        });
        expect(wrapper.get('#campaign-offer-feedback').text()).toContain(
            'Choose or create an Offer',
        );
        expect(wrapper.get('#campaign-offer').attributes('aria-invalid')).toBe(
            'true',
        );
        await wrapper.get('form').trigger('submit');
        expect(post).not.toHaveBeenCalled();
        await wrapper.get('#campaign-offer').setValue('offer-v2');
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);
        expect(wrapper.get('#campaign-offer-feedback').text()).toContain(
            'selection is not saved',
        );
        await wrapper.setProps({ composition: readyComposition });
        expect(wrapper.find('#campaign-offer-feedback').exists()).toBe(false);
        expect(wrapper.get('#campaign-offer').attributes('aria-invalid')).toBe(
            'false',
        );
    });

    it('offers the completed current Objective without silently replacing the saved version', async () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: {
                campaignId: 8,
                composition: {
                    ...composition,
                    goals: [
                        {
                            ...composition.goals[0],
                            measurement_configured: false,
                        },
                        {
                            ...composition.goals[0],
                            id: 'goal-v2',
                            version: 2,
                            current: true,
                            measurement_configured: true,
                        },
                    ],
                },
            },
        });
        expect(
            (wrapper.get('#campaign-goal').element as HTMLSelectElement).value,
        ).toBe('goal-v1');
        expect(wrapper.text()).toContain('already has it configured');
        await wrapper.get('#campaign-update-goal').trigger('click');
        await flushPromises();
        expect(
            (wrapper.get('#campaign-goal').element as HTMLSelectElement).value,
        ).toBe('goal-v2');
        expect(post).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).not.toContain('Open What counts, save');
    });

    beforeEach(() => {
        post.mockClear();
        transform.mockClear();
    });
    it('submits UTC allowance dates and clears an empty optional campaign target', async () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: {
                campaignId: 1,
                composition: {
                    ...composition,
                    sending_starts_at: '2026-09-08T11:00:00Z',
                    sending_ends_at: '2026-09-09T15:00:00Z',
                },
            },
        });
        await wrapper.get('#campaign-target').setValue('2');
        await flushPromises();
        const serialize = transform.mock.calls[0][0];
        const data = serialize({
            target_contribution_count: '',
            sending_starts_at: '2026-09-08T11:00',
            sending_ends_at: '2026-09-09T15:00',
            authority: {
                starts_at: '2026-09-07T10:00',
                ends_at: '2026-09-14T10:00',
            },
        });
        expect(data.target_contribution_count).toBeNull();
        expect(data.sending_starts_at).toBe('2026-09-08T11:00:00Z');
        expect(data.sending_ends_at).toBe('2026-09-09T15:00:00Z');
        expect(data.authority.starts_at).toBe('2026-09-07T10:00:00Z');
        expect(data.authority.ends_at).toBe('2026-09-14T10:00:00Z');
    });
    it('defaults to launch when ready and run until finished without hidden required dates', async () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: { campaignId: 1, composition },
        });
        expect(wrapper.text()).toContain('Run until finished');
        expect(wrapper.find('#campaign-start').exists()).toBe(false);
        expect(wrapper.find('#campaign-end').exists()).toBe(false);
        await wrapper.get('#campaign-target').setValue('2');
        await flushPromises();
        const data = transform.mock.calls[0][0]({
            authority: {},
            sending_starts_at: '2026-09-08T11:00',
            sending_ends_at: '2026-09-09T15:00',
        });
        expect(data.sending_starts_at).toBeNull();
        expect(data.sending_ends_at).toBeNull();
    });
    it('separates the campaign target from a shared allowance and disables expired choices', async () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: {
                campaignId: 1,
                composition: {
                    ...composition,
                    target_contribution_count: 5,
                    execution_authority_scope_id: 'scope',
                    execution_scopes: [
                        {
                            id: 'scope',
                            name: 'September allowance',
                            expired: true,
                            revoked: false,
                            authority: {
                                starts_at: '2026-09-01T00:00:00Z',
                                ends_at: '2026-09-03T00:00:00Z',
                                maximum_people: 10,
                                maximum_accounts: 10,
                                maximum_messages: 20,
                            },
                        },
                    ],
                },
            },
        });
        expect(
            (wrapper.find('#campaign-target').element as HTMLInputElement)
                .value,
        ).toBe('5');
        expect(
            wrapper.find('option[value="scope"]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.text()).toContain(
            'does not reset usage or approve sending',
        );
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'New sending allowance')!
            .trigger('click');
        expect(wrapper.find('#scope-start').exists()).toBe(true);
        expect(wrapper.text()).toContain('No AI spend');
        expect(post).not.toHaveBeenCalled();
    });
    it('shows pinned versions and keeps outcome and acceptance distinct', () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: { campaignId: 1, composition },
        });
        expect(wrapper.text()).toContain('older version');
        expect(wrapper.text()).toContain(
            'outcome is reusable across campaigns',
        );
        expect(wrapper.text()).toContain('acceptance may differ');
        expect(
            wrapper.find('a[href="/objectives/goal?from_campaign=1"]').exists(),
        ).toBe(true);
    });
    it('cancels inline creation without saving or clearing selected library records', async () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: { campaignId: 1, composition },
        });
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'New objective')!
            .trigger('click');
        await wrapper.find('#composition-name').setValue('Unsaved');
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Cancel')!
            .trigger('click');
        expect(wrapper.find('#composition-name').exists()).toBe(false);
        expect(
            (wrapper.find('#campaign-goal').element as HTMLSelectElement).value,
        ).toBe('goal-v1');
        expect(post).not.toHaveBeenCalled();
        await wrapper.find('form').trigger('submit');
        expect(post).not.toHaveBeenCalled();
    });
    it('keeps non-editable selections inspectable but disables mutations', () => {
        const wrapper = mount(CampaignCompositionForm, {
            props: {
                campaignId: 1,
                composition: { ...composition, editable: false },
            },
        });
        expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined();
        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('View objective');
    });
});
