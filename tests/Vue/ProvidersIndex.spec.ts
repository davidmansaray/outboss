import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { router } = vi.hoisted(() => ({
    router: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />' },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router,
    usePage: () => ({ props: { flash: {} } }),
    useForm: (values: Record<string, string>) => ({
        ...values,
        errors: {},
        processing: false,
        reset: vi.fn(),
        clearErrors: vi.fn(),
        submit: vi.fn(),
        put: vi.fn(),
    }),
}));

import ProvidersIndex from '@/pages/providers/Index.vue';

const stubs = {
    AppWorkspace: {
        template:
            '<div><aside><slot name="sidebar" /></aside><div><slot name="crumb" /></div><slot /></div>',
    },
    WorkspaceOpsSidebar: { template: '<div><slot /></div>' },
};

const baseProps = {
    initialTab: 'overview' as const,
    initialActivityView: 'usage' as const,
    isOwner: true,
    workspace: { id: 1, name: 'Personal workspace' },
    providers: [
        {
            id: 10,
            provider: 'openrouter' as const,
            displayName: 'OpenRouter',
            capability: 'llm' as const,
            status: 'healthy' as const,
            credentialDisplay: '••••••••••••',
            lastTestedAt: '2026-08-30T12:00:00Z',
            lastHealthyAt: '2026-08-30T12:00:00Z',
            retryAfterAt: null,
            safeError: null,
            balance: null,
            balanceCheckedAt: null,
        },
        {
            id: 11,
            provider: 'tavily' as const,
            displayName: 'Tavily',
            capability: 'research' as const,
            status: 'healthy' as const,
            credentialDisplay: '••••••••••••',
            lastTestedAt: '2026-08-30T12:00:00Z',
            lastHealthyAt: '2026-08-30T12:00:00Z',
            retryAfterAt: null,
            safeError: null,
            balance: null,
            balanceCheckedAt: null,
        },
    ],
    models: [
        {
            id: 'openai/gpt-5',
            displayName: 'GPT-5',
            tasks: ['account_research'],
            contextTokens: 400000,
            validatedAt: '2026-08-30T00:00:00Z',
        },
    ],
    tasks: [
        {
            task: 'account_research',
            displayName: 'Account research & synthesis',
            modelId: 'openai/gpt-5',
            perRunLimitMicros: 1250000,
            policyVersion: 1,
            requiresResearch: true,
        },
    ],
    budget: {
        currency: 'USD' as const,
        monthlyLimitMicros: 50000000,
        tavilyCreditPriceMicros: 8000,
        committedMicros: 18730000,
        heldMicros: 4200000,
        remainingMicros: 27070000,
        overLimit: false,
        budgetBreach: false,
        resetsAt: '2026-09-01T00:00:00Z',
    },
    usage: [],
    usageFilters: { provider: null, task: null, status: null },
    usagePagination: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
        previousUrl: null,
        nextUrl: null,
    },
    audit: [],
};

describe('AI provider control plane', () => {
    it('renders generic credential masks and keeps health separate from budget state', async () => {
        const wrapper = mount(ProvidersIndex, {
            props: {
                ...baseProps,
                budget: {
                    ...baseProps.budget,
                    remainingMicros: 0,
                    overLimit: true,
                },
            },
            global: { stubs },
        });

        expect(wrapper.findAll('[data-test="provider-row"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('••••••••••••');
        expect(wrapper.text()).not.toContain('private-test-secret');
        expect(wrapper.get('[data-test="budget-blocked"]').text()).toContain(
            'New AI work is budget-blocked',
        );
        expect(wrapper.text()).toContain('Healthy');
    });

    it('removes all provider mutations and the audit tab for members', () => {
        const wrapper = mount(ProvidersIndex, {
            props: { ...baseProps, isOwner: false },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Read-only workspace access');
        expect(wrapper.text()).not.toContain('Change history');
        expect(wrapper.findAll('.service-card footer button')).toHaveLength(0);
    });

    it('keeps services, routing, and activity content in their matching destinations', async () => {
        const wrapper = mount(ProvidersIndex, {
            props: baseProps,
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Services');
        expect(wrapper.text()).toContain('Data handling');
        expect(wrapper.text()).not.toContain('Task defaults');

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Routing & limits')!
            .trigger('click');
        expect(wrapper.text()).toContain('Task defaults');
        expect(wrapper.text()).toContain('Monthly limit');
        expect(wrapper.text()).toContain('Spending policy');
        expect(
            wrapper.findAll('h2').map((heading) => heading.text()),
        ).not.toContain('Services');

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Activity')!
            .trigger('click');
        expect(wrapper.text()).toContain('Usage');
        expect(wrapper.text()).toContain('Change history');
        expect(wrapper.text()).not.toContain('Task defaults');
    });

    it('opens a keyboard-modal spending editor from Routing & limits and restores the trigger', async () => {
        const wrapper = mount(ProvidersIndex, {
            props: { ...baseProps, initialTab: 'routing' },
            attachTo: document.body,
            global: { stubs },
        });
        const trigger = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Edit');
        expect(trigger).toBeDefined();

        await trigger!.trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="dialog"]').attributes('aria-modal')).toBe(
            'true',
        );
        expect(document.activeElement?.getAttribute('inputmode')).toBe(
            'decimal',
        );

        await wrapper.get('[role="dialog"]').trigger('keydown', {
            key: 'Escape',
        });
        await flushPromises();

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(document.activeElement).toBe(trigger!.element);
        wrapper.unmount();
    });
});
