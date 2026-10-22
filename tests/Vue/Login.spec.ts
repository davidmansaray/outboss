import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Form: {
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    Head: { template: '<div />' },
}));

vi.mock('@/routes', () => ({
    register: () => '/register',
}));

vi.mock('@/routes/login', () => ({
    store: { form: () => ({ action: '/login', method: 'post' }) },
}));

vi.mock('@/routes/password', () => ({
    request: () => '/forgot-password',
}));

import Login from '@/pages/auth/Login.vue';

describe('Login', () => {
    it('uses the branded sign-in action', () => {
        const wrapper = mount(Login, {
            props: { canResetPassword: true },
            global: {
                stubs: {
                    PasskeyVerify: { template: '<div />' },
                    PasswordInput: { template: '<input />' },
                    InputError: { template: '<div />' },
                    TextLink: { template: '<a><slot /></a>' },
                    Button: { template: '<button><slot /></button>' },
                    Checkbox: { template: '<input type="checkbox" />' },
                    Input: { template: '<input />' },
                    Label: { template: '<label><slot /></label>' },
                    Spinner: { template: '<span />' },
                },
            },
        });

        expect(wrapper.get('[data-test="login-button"]').text()).toBe(
            'Sign in',
        );
        expect(wrapper.text()).toContain('Remember me');
        expect(wrapper.text()).toContain('Forgot your password?');
    });
});
