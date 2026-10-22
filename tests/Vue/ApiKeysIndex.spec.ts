import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Form: {
        name: 'Form',
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    Head: { name: 'Head', template: '<div />' },
    router: { delete: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
}));

import ApiKeysIndex from '@/pages/api-keys/Index.vue';

const stubs = {
    AppWorkspace: {
        template:
            '<div><aside><slot name="sidebar" /></aside><div class="top"><slot name="crumb" /></div><slot /></div>',
    },
    WorkspaceOpsSidebar: { template: '<div><slot /></div>' },
};

const key = {
    id: 7,
    name: 'Reporting agent',
    scopes: ['campaigns:read'],
    display_prefix: 'ob_live_abc',
    created_at: '2026-08-29T10:00:00Z',
    last_used_at: null,
    expires_at: null,
};

describe('API keys index', () => {
    it('keeps revoke confirmation keyboard-modal and restores trigger focus', async () => {
        const wrapper = mount(ApiKeysIndex, {
            props: {
                keys: [key],
                scopeGroups: [],
                readOnlyScopes: [],
                plainTextToken: null,
            },
            attachTo: document.body,
            global: { stubs },
        });
        const trigger = wrapper.get('[data-test="revoke-api-key"]');

        await trigger.trigger('click');
        await flushPromises();

        const dialog = wrapper.get('[role="dialog"]');
        const buttons = dialog.findAll('button');
        const cancel = buttons[0]!;
        const confirm = buttons[1]!;

        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(document.activeElement).toBe(cancel.element);

        confirm.element.focus();
        await dialog.trigger('keydown', { key: 'Tab' });
        expect(document.activeElement).toBe(cancel.element);

        cancel.element.focus();
        await dialog.trigger('keydown', { key: 'Tab', shiftKey: true });
        expect(document.activeElement).toBe(confirm.element);

        await dialog.trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(document.activeElement).toBe(trigger.element);

        wrapper.unmount();
    });
});
