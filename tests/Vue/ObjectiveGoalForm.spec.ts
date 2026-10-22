import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.hoisted(() => vi.fn());
const patch = vi.hoisted(() => vi.fn());
const forms = vi.hoisted(() => [] as Record<string, unknown>[]);
vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: Record<string, unknown>) => {
        forms.push(data);

        return reactive({
            ...data,
            errors: {},
            processing: false,
            post,
            patch,
        });
    },
}));
import ObjectiveGoalForm from '@/components/objectives/ObjectiveGoalForm.vue';

describe('Objective goal form', () => {
    it('includes campaign return context in its save payload', () => {
        mount(ObjectiveGoalForm, {
            props: { returnCampaign: { id: 9, name: 'Campaign' } },
        });
        expect(forms.at(-1)?.from_campaign).toBe(9);
    });
    it('renders without secure-context randomUUID and submits independent intent', async () => {
        const randomUUID = vi
            .spyOn(crypto, 'randomUUID')
            .mockImplementation(() => {
                throw new Error('Unavailable on local HTTP');
            });
        const wrapper = mount(ObjectiveGoalForm);
        expect(wrapper.text()).toContain('Meeting booked');
        expect(wrapper.text()).toContain('does not select prospects');
        expect(wrapper.find('input[type="number"]').exists()).toBe(false);
        expect(wrapper.find('input[type="date"]').exists()).toBe(false);
        await wrapper.get('input').setValue('Book discovery meetings');
        await wrapper.get('form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/objectives');
        expect(randomUUID).not.toHaveBeenCalled();
        randomUUID.mockRestore();
    });
    it('edits through the same domain transport with the existing Objective', async () => {
        const wrapper = mount(ObjectiveGoalForm, {
            props: {
                objective: {
                    id: 'goal-1',
                    name: 'Meetings',
                    description: null,
                    measurement: 'meeting_booked',
                    target_count: 5,
                    deadline: null,
                    lock_version: 2,
                },
            },
        });
        await wrapper.get('form').trigger('submit');
        expect(patch).toHaveBeenCalledWith('/objectives/goal-1', {
            preserveScroll: true,
        });
    });
});
