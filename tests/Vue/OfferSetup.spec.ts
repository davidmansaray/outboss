import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            isDirty: false,
            post,
            defaults: vi.fn(),
            reset: vi.fn(),
        }),
}));
import OfferSetup from '@/pages/offers/Setup.vue';

const offer = {
    id: 'offer-1',
    version_id: 'version-2',
    version: 2,
    lock_version: 1,
    status: 'draft',
    payload: {
        name: 'Consultation',
        promise: 'Review a process',
        target_audience: 'Owners',
        desired_outcome: 'An action list',
        call_to_action: 'Book a call',
    },
    readiness: { state: 'ready_for_review', blockers: [] },
};
const global = { stubs: { AppWorkspace: { template: '<div><slot /></div>' } } };
describe('Offer setup', () => {
    it('exposes explicit review separately from campaign launch', async () => {
        const wrapper = mount(OfferSetup, {
            props: { offer, definitions: [], is_owner: true },
            global,
        });
        expect(wrapper.text()).toContain(
            'does not approve or launch a campaign',
        );
        expect(wrapper.text()).toContain('Create meeting measurement');
        const approve = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Approve version 2')!;
        await approve.trigger('click');
        expect(post).toHaveBeenCalledWith('/offers/offer-1/setup/review', {
            preserveScroll: true,
        });
    });
    it('keeps active contracts and member views read-only', () => {
        for (const props of [
            {
                offer: { ...offer, status: 'active' },
                definitions: [],
                is_owner: true,
            },
            { offer, definitions: [], is_owner: false },
        ]) {
            const wrapper = mount(OfferSetup, { props, global });
            expect(
                wrapper
                    .findAll('fieldset')
                    .every(
                        (field) => field.attributes('disabled') !== undefined,
                    ),
            ).toBe(true);
            expect(wrapper.text()).not.toContain('Save offer version');
        }
    });
});
