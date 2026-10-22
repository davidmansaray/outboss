import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
const forms = vi.hoisted(() => [] as Record<string, unknown>[]);
vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        forms.push(data);

        return reactive({ ...data, errors: {}, processing: false });
    },
}));
import ObjectiveMeasurementForm from '@/components/objectives/ObjectiveMeasurementForm.vue';
const objective = {
    id: 'goal',
    name: 'Meetings',
    description: null,
    measurement: 'meeting_booked',
    lock_version: 1,
};
describe('Objective measurement', () => {
    it('preserves campaign return context for creation and saving', () => {
        mount(ObjectiveMeasurementForm, {
            props: {
                objective,
                definitionVersionId: null,
                definitions: [],
                returnCampaign: { id: 9, name: 'Campaign' },
            },
        });
        expect(forms.slice(-2).map((form) => form.from_campaign)).toEqual([
            9, 9,
        ]);
    });
    it('keeps sending limits and dates out of Objective authoring', () => {
        const wrapper = mount(ObjectiveMeasurementForm, {
            props: {
                objective,
                definitionVersionId: null,
                definitions: [{ id: 'definition', name: 'Meeting booked' }],
            },
        });
        expect(wrapper.text()).toContain('What counts');
        expect(wrapper.text()).toContain('configured separately');
        expect(wrapper.findAll('input')).toHaveLength(0);
        expect(wrapper.text()).toContain('Save measurement');
    });
    it('offers independent measurement creation when no definition exists', () => {
        const wrapper = mount(ObjectiveMeasurementForm, {
            props: { objective, definitionVersionId: null, definitions: [] },
        });
        expect(
            wrapper.find('button[type="submit"]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.text()).toContain('No Offer is required');
        expect(
            wrapper.find('button[type="button"]').attributes('disabled'),
        ).toBeUndefined();
    });
});
