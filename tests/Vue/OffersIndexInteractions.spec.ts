import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { get, on, reload, visit, getRequest, patchRequest, deleteRequest } =
    vi.hoisted(() => ({
        get: vi.fn(),
        on: vi.fn(() => vi.fn()),
        reload: vi.fn(),
        visit: vi.fn(),
        getRequest: vi.fn(),
        patchRequest: vi.fn(),
        deleteRequest: vi.fn(),
    }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { get, on, reload, visit },
    usePage: () => ({ props: { auth: { workspace: { role: 'owner' } } } }),
}));

vi.mock('@/lib/jsonClient', () => ({
    jsonClient: {
        get: getRequest,
        patch: patchRequest,
        delete: deleteRequest,
    },
    JsonHttpError: class JsonHttpError extends Error {
        errors: Record<string, string> = {};
    },
}));

import OffersIndex from '@/pages/offers/Index.vue';
import type { Offer } from '@/types/offers';

const offer: Offer = {
    uuid: 'offer-1',
    name: 'Pipeline sprint',
    promise: 'Build qualified demand.',
    target_audience: 'Revenue teams',
    desired_outcome: 'Qualified demand',
    call_to_action: 'Book a session',
    notes: '',
    status: 'draft',
    lock_version: 7,
    version_count: 1,
};

const stubs = {
    AppWorkspace: {
        template:
            '<div><aside><slot name="sidebar" /></aside><div class="top"><slot name="actions" /></div><slot /></div>',
    },
    OfferSidebar: defineComponent({
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template:
            '<nav data-test="offer-sidebar"><button data-test="active-filter" @click="$emit(\'update:modelValue\', \'active\')">Active</button></nav>',
    }),
    OfferList: defineComponent({
        props: {
            offers: { type: Array, required: true },
        },
        emits: ['status'],
        template:
            '<div data-test="offer-list">{{ offers.length }} offers<button data-test="activate" @click="$emit(\'status\', offers[0], \'active\')">Activate</button></div>',
    }),
    OfferEditorDrawer: { template: '<div data-test="offer-editor" />' },
    OfferHistoryDrawer: { template: '<div data-test="offer-history" />' },
};

describe('Offers index interactions', () => {
    beforeEach(() => {
        get.mockReset();
        on.mockClear();
        reload.mockReset();
        visit.mockReset();
        patchRequest.mockReset();
        getRequest.mockReset();
        deleteRequest.mockReset();
        patchRequest.mockResolvedValue({
            ...offer,
            status: 'active',
            lock_version: 8,
        });
        window.history.replaceState({}, '', '/offers');
    });

    it('sends the selected owner status with its optimistic lock and reloads offers', async () => {
        const wrapper = mount(OffersIndex, {
            props: { offers: [offer], isOwner: true },
            global: { stubs },
        });

        await wrapper.get('[data-test="activate"]').trigger('click');
        await flushPromises();

        expect(patchRequest).toHaveBeenCalledTimes(1);
        expect(patchRequest).toHaveBeenCalledWith('/offers/offer-1/status', {
            status: 'active',
            expected_lock_version: 7,
            client_operation_id: expect.any(String),
        });
        expect(reload).toHaveBeenCalledWith({
            only: ['offers', 'offerPagination', 'offerCounts'],
        });
        wrapper.unmount();
    });

    it('loads the next cursor page without duplicating existing Offers', async () => {
        const nextOffer = { ...offer, uuid: 'offer-2', name: 'Second offer' };
        getRequest.mockResolvedValue({
            offers: [offer, nextOffer],
            pagination: {
                next_cursor: null,
                has_more: false,
                per_page: 1,
            },
            counts: {
                all: 2,
                draft: 2,
                active: 0,
                paused: 0,
                archived: 0,
            },
        });
        const wrapper = mount(OffersIndex, {
            props: {
                offers: [offer],
                offerPagination: {
                    next_cursor: 'opaque-cursor',
                    has_more: true,
                    per_page: 1,
                },
                offerCounts: {
                    all: 2,
                    draft: 2,
                    active: 0,
                    paused: 0,
                    archived: 0,
                },
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper.get('.offers-load-more button').trigger('click');
        await flushPromises();

        expect(getRequest).toHaveBeenCalledWith(
            '/offers?cursor=opaque-cursor&per_page=1',
        );
        expect(wrapper.get('[data-test="offer-list"]').text()).toContain(
            '2 offers',
        );
        expect(wrapper.find('.offers-load-more').exists()).toBe(false);
        wrapper.unmount();
    });

    it('requests the selected status from the server instead of filtering one loaded page', async () => {
        const wrapper = mount(OffersIndex, {
            props: { offers: [offer], isOwner: true },
            global: { stubs },
        });

        await wrapper.get('[data-test="active-filter"]').trigger('click');

        expect(get).toHaveBeenCalledWith(
            '/offers',
            { status: 'active' },
            {
                only: [
                    'offers',
                    'offerPagination',
                    'offerCounts',
                    'offerFilter',
                ],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
        wrapper.unmount();
    });

    it('keeps the status scope on cursor pagination requests', async () => {
        getRequest.mockResolvedValue({
            offers: [],
            pagination: {
                next_cursor: null,
                has_more: false,
                per_page: 10,
            },
            counts: {
                all: 31,
                draft: 5,
                active: 26,
                paused: 0,
                archived: 0,
            },
            filter: 'active',
        });
        const wrapper = mount(OffersIndex, {
            props: {
                offers: [offer],
                offerFilter: 'active',
                offerPagination: {
                    next_cursor: 'active-cursor',
                    has_more: true,
                    per_page: 10,
                },
                offerCounts: {
                    all: 31,
                    draft: 5,
                    active: 26,
                    paused: 0,
                    archived: 0,
                },
                isOwner: true,
            },
            global: { stubs },
        });

        await wrapper.get('.offers-load-more button').trigger('click');
        await flushPromises();

        expect(getRequest).toHaveBeenCalledWith(
            '/offers?cursor=active-cursor&per_page=10&status=active',
        );
        wrapper.unmount();
    });

    it('keeps member status actions inert even if a child emits a mutation event', async () => {
        const wrapper = mount(OffersIndex, {
            props: { offers: [offer], isOwner: false },
            global: { stubs },
        });

        await wrapper.get('[data-test="activate"]').trigger('click');
        await flushPromises();

        expect(patchRequest).not.toHaveBeenCalled();
        expect(wrapper.get('.offers-member-note').text()).toContain(
            'Members can inspect',
        );
        wrapper.unmount();
    });
});
