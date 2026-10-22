import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import OfferList from '@/components/offers/OfferList.vue';
import OfferSidebar from '@/components/offers/OfferSidebar.vue';
import type { Offer } from '@/types/offers';

const offers: Offer[] = [
    {
        uuid: 'offer-1',
        name: 'Pipeline sprint',
        promise: 'Create a qualified pipeline plan in two weeks.',
        target_audience: 'B2B SaaS revenue teams',
        desired_outcome: 'A focused outbound motion',
        call_to_action: 'Book a working session',
        notes: '',
        status: 'active',
        lock_version: 2,
        version_count: 3,
    },
    {
        uuid: 'offer-2',
        name: 'Research audit',
        promise: 'Find the next segment worth testing.',
        target_audience: 'Early-stage founders',
        desired_outcome: 'A defensible segment thesis',
        call_to_action: 'Start an audit',
        notes: '',
        status: 'draft',
        lock_version: 1,
        version_count: 1,
    },
];

const statusMenuStub = {
    props: ['status', 'isOwner', 'disabled'],
    template: '<span class="status-menu-stub">{{ status }}</span>',
};

describe('Offers surface', () => {
    it('provides contextual counts and filters in the secondary rail', async () => {
        const wrapper = mount(OfferSidebar, {
            props: { offers, modelValue: 'all' },
        });

        expect(wrapper.findAll('button')).toHaveLength(5);
        expect(wrapper.findAll('button')[0].text()).toContain('2');

        const active = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Active'))!;
        await active.trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['active']);
        wrapper.unmount();
    });

    it('shows proposition content and lifecycle without inventing performance metrics', () => {
        const wrapper = mount(OfferList, {
            props: { offers, isOwner: false },
            global: { stubs: { OfferStatusMenu: statusMenuStub } },
        });

        expect(wrapper.findAll('.offer-row')).toHaveLength(2);
        expect(wrapper.text()).toContain('Pipeline sprint');
        expect(wrapper.text()).toContain('B2B SaaS revenue teams');
        expect(wrapper.text()).not.toMatch(
            /conversion rate|reply rate|win rate/i,
        );
        expect(wrapper.findAll('.status-menu-stub')).toHaveLength(2);
        wrapper.unmount();
    });

    it('renders an honest empty state for a selected lifecycle filter', () => {
        const wrapper = mount(OfferList, {
            props: { offers: [], filter: 'archived' },
            global: { stubs: { OfferStatusMenu: statusMenuStub } },
        });

        expect(wrapper.get('[data-test="offer-empty-state"]').text()).toContain(
            'No archived offers',
        );
        expect(wrapper.findAll('.offer-row')).toHaveLength(0);
        wrapper.unmount();
    });
});
