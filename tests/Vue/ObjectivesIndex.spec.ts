import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const routerPost = vi.hoisted(() => vi.fn());
const routerReload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router: { post: routerPost, reload: routerReload },
}));

import ObjectivesIndex from '@/pages/objectives/Index.vue';

const readyObjective = {
    id: 'objective-ready',
    status: 'ready',
    name: 'Book qualified workshops',
    description: null,
    target: { kind: 'count' as const, count: 4, value: null, currency: null },
    horizon: {
        starts_at: '2026-09-05T12:00:00Z',
        ends_at: '2026-09-20T12:00:00Z',
    },
    readiness: { ready: true, blockers: [], warnings: [] },
    experiment: {
        id: 'experiment-ready',
        name: 'Evidence-led control',
        status: 'assigned',
        arms: 1,
        assignments: 4,
    },
    latest_run: null,
    plan: null,
    start_blockers: [],
    attention_blockers: [],
    execution_underway: false,
    can_start_run: true,
    updated_at: '2026-09-04T12:00:00Z',
};

const plannedObjective = {
    ...readyObjective,
    id: 'objective-planned',
    name: 'Win expansion calls',
    latest_run: {
        id: 'run-1',
        number: 1,
        state: 'running',
        phase: 'execute',
        updated_at: '2026-09-04T13:00:00Z',
    },
    plan: {
        id: 'plan-1',
        version: 1,
        status: 'executing',
        actions: 3,
        href: '/execution-plans/plan-1',
    },
    start_blockers: [
        {
            code: 'objective_not_ready',
            message: 'Move this Objective to ready before generating a plan.',
        },
        {
            code: 'run_already_active',
            message: 'This Objective already has a nonterminal run.',
        },
    ],
    attention_blockers: [],
    execution_underway: true,
    can_start_run: false,
};

const global = {
    stubs: {
        AppWorkspace: {
            props: ['area', 'title'],
            template:
                '<div><aside><slot name="sidebar" /></aside><header><slot name="actions" /></header><slot /></div>',
        },
    },
};

beforeEach(() => {
    routerPost.mockClear();
    routerReload.mockClear();
});

describe('Objectives hub', () => {
    it('shows current readiness, runs, and the primary plan review link', () => {
        const wrapper = mount(ObjectivesIndex, {
            props: {
                is_owner: true,
                objectives: [readyObjective, plannedObjective],
            },
            global,
        });

        expect(wrapper.text()).toContain('Book qualified workshops');
        expect(wrapper.text()).toContain('4 conversions');
        expect(wrapper.text()).toContain('Evidence-led control');
        expect(wrapper.text()).toContain('Run 1 · Running');
        expect(wrapper.text()).toContain('Execution underway');
        expect(wrapper.text()).not.toContain(
            'Move this Objective to ready before generating a plan.',
        );
        expect(wrapper.text()).toContain('3 exact actions');
        expect(wrapper.get('.review-link').attributes('href')).toBe(
            '/execution-plans/plan-1',
        );
        expect(wrapper.get('.review-link').text()).toContain('Review plan');
    });

    it('starts one exact experiment with a retained idempotency key', async () => {
        const wrapper = mount(ObjectivesIndex, {
            props: { is_owner: true, objectives: [readyObjective] },
            global,
        });

        await wrapper.get('.objective-action button').trigger('click');

        expect(routerPost).toHaveBeenCalledWith(
            '/objectives/objective-ready/runs',
            {
                gtm_experiment_id: 'experiment-ready',
                idempotency_key: expect.any(String),
            },
            expect.objectContaining({
                preserveScroll: true,
                headers: { 'Idempotency-Key': expect.any(String) },
            }),
        );
        const payload = routerPost.mock.calls[0][1];
        const options = routerPost.mock.calls[0][2];
        expect(options.headers['Idempotency-Key']).toBe(
            payload.idempotency_key,
        );
    });

    it('shows readiness without owner mutation controls', () => {
        const wrapper = mount(ObjectivesIndex, {
            props: {
                is_owner: false,
                objectives: [{ ...readyObjective, can_start_run: false }],
            },
            global,
        });

        expect(wrapper.find('.objective-action button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Owner action');
    });

    it('retains a key across ambiguous failure and rotates it after acknowledged creation', async () => {
        const wrapper = mount(ObjectivesIndex, {
            props: { is_owner: true, objectives: [readyObjective] },
            global,
        });
        await wrapper.get('.objective-action button').trigger('click');
        const firstKey = routerPost.mock.calls[0][1].idempotency_key;
        routerPost.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();
        await wrapper.get('.objective-action button').trigger('click');
        expect(routerPost.mock.calls[1][1].idempotency_key).toBe(firstKey);
        routerPost.mock.calls[1][2].onSuccess();
        routerPost.mock.calls[1][2].onFinish();
        await wrapper.vm.$nextTick();
        await wrapper.get('.objective-action button').trigger('click');
        expect(routerPost.mock.calls[2][1].idempotency_key).not.toBe(firstKey);
    });
});
