import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GtmProfileResearchState from '@/components/gtm-profile/GtmProfileResearchState.vue';
import type { GtmResearchStateName } from '@/types/gtmProfile';

const LinkStub = {
    props: ['href'],
    template: '<a :href="href"><slot /></a>',
};

describe('GtmProfileResearchState', () => {
    it('renders every non-terminal and terminal recovery state', async () => {
        const states: GtmResearchStateName[] = [
            'not_started',
            'awaiting_provider',
            'awaiting_budget',
            'queued',
            'running',
            'ready',
            'failed',
            'cancelled',
        ];
        const wrapper = mount(GtmProfileResearchState, {
            props: { researchState: { state: 'not_started' }, isOwner: true },
            global: { stubs: { Link: LinkStub } },
        });

        for (const state of states) {
            await wrapper.setProps({
                researchState: {
                    state,
                    stale: state === 'ready',
                    source_count: 2,
                },
            });

            expect(
                wrapper.get('[data-test="gtm-research-state"]').text(),
            ).toMatch(
                state === 'not_started'
                    ? /Add a website/
                    : state === 'awaiting_provider'
                      ? /provider setup/
                      : state === 'awaiting_budget'
                        ? /workspace budget/
                        : state === 'queued'
                          ? /Research queued/
                          : state === 'running'
                            ? /Researching your company/
                            : state === 'ready'
                              ? /stale/
                              : /Research needs another try|Research was cancelled/,
            );
        }

        wrapper.unmount();
    });

    it('removes owner-only recovery actions for members', () => {
        const wrapper = mount(GtmProfileResearchState, {
            props: {
                researchState: { state: 'failed' },
                isOwner: false,
            },
            global: { stubs: { Link: LinkStub } },
        });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Research needs another try');
        wrapper.unmount();
    });

    it('warns about a lost paid result and requires confirmation for a new run', async () => {
        const wrapper = mount(GtmProfileResearchState, {
            props: {
                researchState: {
                    state: 'failed',
                    safe_error_code: 'paid_result_lost',
                    run_uuid: 'lost-run',
                },
                isOwner: true,
            },
            global: { stubs: { Link: LinkStub } },
        });

        expect(wrapper.text()).toContain('cannot be replayed');
        expect(wrapper.text()).toContain('may repeat paid work');
        expect(wrapper.text()).not.toContain('Retry starts safely');

        await wrapper
            .get('[data-test="gtm-paid-result-new-run"]')
            .trigger('click');
        expect(wrapper.emitted('retry')).toBeUndefined();
        expect(
            wrapper.get('[data-test="gtm-paid-result-confirmation"]').text(),
        ).toContain('another provider charge');

        const confirm = wrapper
            .findAll('[data-test="gtm-paid-result-confirmation"] button')
            .find((button) => button.text() === 'Confirm new run');
        await confirm?.trigger('click');

        expect(wrapper.emitted('retry')).toEqual([[]]);
        wrapper.unmount();
    });
});
