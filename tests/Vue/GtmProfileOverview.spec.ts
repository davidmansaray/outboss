import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GtmProfileOverview from '@/components/gtm-profile/GtmProfileOverview.vue';
import type {
    GtmProfile,
    GtmProfileReviewItem,
    GtmResearchState,
} from '@/types/gtmProfile';

const profile: GtmProfile = {
    uuid: 'profile-1',
    lock_version: 4,
    payload: {
        synthesis: {
            value: 'Acme helps revenue teams turn qualified demand into pipeline.',
            provenance: 'ai_inferred',
        },
        company: { name: 'Acme', product_description: 'Revenue platform' },
        market: { target_segment: 'B2B SaaS' },
        buyers: { target_roles: ['VP Revenue'] },
        messaging: { proof_points: ['Customer results'] },
        qualification: { dimensions: ['segment fit'] },
    },
};

const researchState: GtmResearchState = {
    state: 'ready',
    source_count: 3,
    last_researched_at: '2026-08-30T12:00:00Z',
};

const reviewItems: GtmProfileReviewItem[] = [
    {
        uuid: 'review-1',
        field_path: 'market.target_segment',
        type: 'conflict',
        proposed_value: 'Growth-stage SaaS',
        status: 'open',
    },
];

const stubs = {
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    GtmProfileResearchState: {
        template: '<div data-test="research-state" />',
    },
};

describe('GtmProfileOverview', () => {
    it('keeps the default view synthesis-first with exactly five knowledge rows', () => {
        const wrapper = mount(GtmProfileOverview, {
            props: {
                profile,
                researchState,
                reviewItems,
                activeOffersCount: 3,
                isOwner: true,
            },
            global: { stubs },
        });

        expect(wrapper.get('h1').text()).toBe('GTM profile');
        expect(wrapper.text()).toContain('Acme helps revenue teams');
        expect(wrapper.get('[data-test="gtm-review-action"]').text()).toContain(
            '1 details could improve results',
        );
        expect(wrapper.get('a[href="/offers"]').text()).toContain(
            '3 active offers',
        );
        expect(wrapper.findAll('.gtm-knowledge-row')).toHaveLength(5);
        expect(
            wrapper.find('.gtm-knowledge-row[data-section="offers"]').exists(),
        ).toBe(false);
        wrapper.unmount();
    });

    it('removes owner mutation controls for members while retaining knowledge', () => {
        const wrapper = mount(GtmProfileOverview, {
            props: {
                profile,
                researchState,
                reviewItems,
                activeOffersCount: 0,
                isOwner: false,
            },
            global: { stubs },
        });

        expect(wrapper.find('button').text()).not.toContain('Edit profile');
        expect(wrapper.find('[data-test="gtm-review-action"]').exists()).toBe(
            false,
        );
        expect(wrapper.findAll('.gtm-knowledge-row')).toHaveLength(5);
        wrapper.unmount();
    });
});
