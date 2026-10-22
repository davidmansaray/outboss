import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const { formPost } = vi.hoisted(() => ({
    formPost: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        const form = reactive({
            ...initial,
            processing: false,
            errors: {} as Record<string, string>,
            post: formPost,
            reset() {
                Object.assign(this, initial);
            },
            clearErrors(...keys: string[]) {
                if (!keys.length) {
                    this.errors = {};

                    return;
                }

                for (const key of keys) {
                    delete this.errors[key];
                }
            },
            setError(key: string, message: string) {
                this.errors[key] = message;
            },
        });

        return form;
    },
}));

import CreateWorkspaceDialog from '@/components/CreateWorkspaceDialog.vue';

const stubs = {
    Dialog: {
        props: ['open'],
        template: '<div v-if="open"><slot /></div>',
    },
    DialogContent: {
        template: '<div role="dialog" aria-modal="true"><slot /></div>',
    },
    DialogDescription: { template: '<p><slot /></p>' },
    DialogFooter: { template: '<footer><slot /></footer>' },
    DialogHeader: { template: '<header><slot /></header>' },
    DialogTitle: { template: '<h2><slot /></h2>' },
    Input: {
        props: ['modelValue', 'id', 'name', 'type', 'disabled'],
        emits: ['update:modelValue'],
        template:
            '<input :id="id" :name="name" :type="type || \'text\'" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
    Label: { template: '<label><slot /></label>' },
    Button: {
        props: ['type', 'disabled'],
        template: '<button :type="type" :disabled="disabled"><slot /></button>',
    },
};

function mountDialog() {
    return mount(CreateWorkspaceDialog, {
        props: { open: true },
        attachTo: document.body,
        global: {
            stubs,
        },
    });
}

describe('CreateWorkspaceDialog', () => {
    beforeEach(() => formPost.mockReset());

    it('uses the progressive name then optional website flow', async () => {
        const wrapper = mountDialog();

        expect(wrapper.text()).toContain('New workspace · 1 of 2');
        await wrapper.get('#workspace-name').setValue(' Acme Labs ');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.text()).toContain('What’s the company website?');
        expect(wrapper.text()).toContain('New workspace · 2 of 2');
        expect(formPost).not.toHaveBeenCalled();

        await wrapper.get('#workspace-website').setValue('acme.test');
        await wrapper.get('form').trigger('submit');

        expect(formPost).toHaveBeenCalledTimes(1);
        const [url, options] = formPost.mock.calls[0];
        expect(url).toBe('/workspaces');
        expect(options).toBeDefined();

        const form = wrapper.vm.$.setupState.form as Record<string, unknown>;
        expect(form.name).toBe('Acme Labs');
        expect(form.website_url).toBe('acme.test');
        expect(form.client_operation_id).toEqual(expect.any(String));

        wrapper.unmount();
        await flushPromises();
    });

    it('allows the two-step flow to skip the optional website without posting a blank value', async () => {
        const wrapper = mountDialog();

        await wrapper.get('#workspace-name').setValue('Acme Labs');
        await wrapper.get('form').trigger('submit');
        const skip = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Skip website');

        expect(skip).toBeDefined();
        await skip!.trigger('click');

        expect(formPost).toHaveBeenCalledTimes(1);
        const form = wrapper.vm.$.setupState.form as Record<string, unknown>;
        expect(form.website_url).toBeNull();
        expect(form.client_operation_id).toEqual(expect.any(String));

        wrapper.unmount();
    });

    it('keeps the operation key and form values for a lost-response retry, even after reopening', async () => {
        const wrapper = mountDialog();

        await wrapper.get('#workspace-name').setValue('Retry Labs');
        await wrapper.get('form').trigger('submit');
        await wrapper.get('#workspace-website').setValue('retry.example');
        await wrapper.get('form').trigger('submit');

        const form = wrapper.vm.$.setupState.form as Record<string, unknown>;
        const operationId = form.client_operation_id;
        expect(operationId).toEqual(expect.any(String));

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await wrapper.get('form').trigger('submit');

        expect(formPost).toHaveBeenCalledTimes(2);
        expect(form.client_operation_id).toBe(operationId);
        expect(form.name).toBe('Retry Labs');
        expect(form.website_url).toBe('retry.example');
        wrapper.unmount();
    });
});
