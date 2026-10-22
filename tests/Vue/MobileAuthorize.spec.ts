import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const setLayoutProps = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Form: {
        name: 'Form',
        template:
            '<form v-bind="$attrs"><slot :errors="{}" :processing="false" /></form>',
    },
    Head: { name: 'Head', template: '<div />' },
    setLayoutProps: (props: unknown) => setLayoutProps(props),
}));

vi.mock(
    '@/actions/App/Http/Controllers/Mobile/MobileAuthorizeController',
    () => ({
        default: {
            store: {
                form: () => ({ action: '/mobile/authorize', method: 'post' }),
            },
        },
    }),
);

import MobileAuthorize from '@/pages/auth/MobileAuthorize.vue';

const request = {
    client: 'ios',
    redirect_uri: 'outboss://auth/callback',
    code_challenge: 'challenge-value',
    code_challenge_method: 'S256',
    state: 'state-abc123',
    device_name: 'Work iPhone',
};

const workspaces = [
    { id: 4, name: 'Acme Labs', role: 'member' },
    { id: 9, name: 'Personal', role: 'owner' },
];

function hiddenFields(form: Element): Record<string, string> {
    return Object.fromEntries(
        Array.from(form.querySelectorAll('input[type="hidden"]')).map(
            (input) => [
                (input as HTMLInputElement).name,
                (input as HTMLInputElement).value,
            ],
        ),
    );
}

describe('MobileAuthorize', () => {
    beforeEach(() => setLayoutProps.mockClear());

    it('asks the person to choose a workspace, one row per workspace', () => {
        const wrapper = mount(MobileAuthorize, {
            props: {
                invalid: false,
                request,
                email: 'dana@example.com',
                workspaces,
            },
        });

        expect(setLayoutProps).toHaveBeenLastCalledWith({
            title: 'Choose a workspace',
            description:
                'Signed in as dana@example.com. You can switch workspaces in the app at any time.',
        });

        const rows = wrapper.findAll('button[name="workspace_id"]');
        expect(rows.map((row) => row.attributes('value'))).toEqual(['4', '9']);
        expect(rows.map((row) => row.attributes('type'))).toEqual([
            'submit',
            'submit',
        ]);
        expect(rows[0].text()).toContain('Acme Labs');
        expect(rows[0].text()).toContain('member');

        // No permission list and no Allow / Don't allow buttons.
        expect(wrapper.text()).not.toContain('Allow');
        expect(wrapper.text()).not.toContain('This phone will be able to');

        const form = wrapper.get('[data-test="mobile-authorize-form"]');
        expect(hiddenFields(form.element)).toEqual({
            decision: 'allow',
            ...request,
        });
    });

    it('keeps a cancel link that denies access', () => {
        const wrapper = mount(MobileAuthorize, {
            props: {
                invalid: false,
                request: { ...request, device_name: null },
                email: 'dana@example.com',
                workspaces,
            },
        });

        const cancel = wrapper.get('[data-test="mobile-authorize-cancel"]');
        expect(cancel.text()).toBe('Cancel');
        expect(cancel.attributes('name')).toBeUndefined();

        const form = wrapper.get('[data-test="mobile-authorize-cancel-form"]');
        const fields = hiddenFields(form.element);
        expect(fields.decision).toBe('deny');
        expect(fields.state).toBe('state-abc123');
        expect(fields).not.toHaveProperty('device_name');
    });

    it('explains an invalid sign-in link without any workspace choice', () => {
        const wrapper = mount(MobileAuthorize, { props: { invalid: true } });

        expect(
            wrapper.get('[data-test="mobile-authorize-invalid"]').text(),
        ).toContain("This sign-in link isn't valid.");
        expect(wrapper.find('button').exists()).toBe(false);
    });
});
