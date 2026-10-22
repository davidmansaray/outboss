import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';

const { get, on, reload, visit } = vi.hoisted(() => ({
    get: vi.fn(),
    on: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
let inertiaBefore: ((event: Event) => boolean | void) | null = null;
let inertiaNavigate: (() => void) | null = null;

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { get, on, reload, visit },
    usePage: () => ({ props: { auth: { workspace: { role: 'owner' } } } }),
}));

vi.mock('@/lib/jsonClient', () => ({
    jsonClient: { get: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    JsonHttpError: class JsonHttpError extends Error {
        errors: Record<string, string> = {};
    },
}));

import OffersIndex from '@/pages/offers/Index.vue';
import type { Offer } from '@/types/offers';

const offers: Offer[] = [
    {
        uuid: 'offer-1',
        name: 'Pipeline sprint',
        promise: 'Build qualified demand.',
        target_audience: 'Revenue teams',
        desired_outcome: 'Qualified demand',
        call_to_action: 'Book a session',
        status: 'draft',
        lock_version: 1,
        version_count: 1,
    },
];

const stubs = {
    AppWorkspace: {
        template:
            '<div><aside><slot name="sidebar" /></aside><div class="top"><slot name="actions" /></div><slot /></div>',
    },
    OfferSidebar: { template: '<nav data-test="offer-sidebar" />' },
    OfferList: {
        props: ['offers'],
        template:
            '<div data-test="offer-list">{{ offers.length }} offers</div>',
    },
    OfferEditorDrawer: defineComponent({
        props: ['open'],
        emits: ['update:open', 'dirty-change'],
        setup(_props, { expose }) {
            expose({
                guardNavigation(action: () => void) {
                    action();

                    return true;
                },
            });
        },
        template:
            '<div v-if="open" data-test="offer-editor"><button data-test="close-editor" @click="$emit(\'update:open\', false)">Close</button></div>',
    }),
    OfferHistoryDrawer: { template: '<div data-test="offer-history" />' },
    WorkspaceOpsSidebar: { template: '<div data-test="ops-sidebar" />' },
};

describe('Offers index', () => {
    beforeEach(() => {
        get.mockReset();
        on.mockReset();
        reload.mockReset();
        visit.mockReset();
        inertiaBefore = null;
        inertiaNavigate = null;
        on.mockImplementation(
            (eventName: string, handler: (event: Event) => boolean | void) => {
                if (eventName === 'before') {
                    inertiaBefore = handler;
                }

                if (eventName === 'navigate') {
                    inertiaNavigate = handler;
                }

                return vi.fn();
            },
        );
        window.history.replaceState({}, '', '/offers');
    });

    afterEach(() => vi.restoreAllMocks());

    it('renders the Offers shell and opens a URL-backed create drawer', async () => {
        const wrapper = mount(OffersIndex, {
            props: { offers, isOwner: true },
            global: { stubs },
        });

        expect(wrapper.get('[data-test="offers-page"]').text()).toContain(
            'Offers',
        );
        expect(
            wrapper.get('.offers-new-button').attributes('disabled'),
        ).toBeUndefined();
        await wrapper.get('.offers-new-button').trigger('click');

        expect(wrapper.get('[data-test="offer-editor"]').exists()).toBe(true);
        expect(window.location.search).toContain('panel=edit');
        expect(window.location.search).not.toContain('offer=');
        wrapper.unmount();
    });

    it('shows the honest member state without a mutation action', () => {
        const wrapper = mount(OffersIndex, {
            props: { offers, isOwner: false },
            global: { stubs },
        });

        expect(wrapper.get('.offers-member-note').text()).toContain(
            'Members can inspect',
        );
        expect(
            wrapper.get('.offers-new-button').attributes('disabled'),
        ).toBeDefined();
        wrapper.unmount();
    });

    it('consumes a locally pushed overlay entry when it closes', async () => {
        const back = vi.spyOn(window.history, 'back');
        const replace = vi.spyOn(window.history, 'replaceState');
        const wrapper = mount(OffersIndex, {
            props: { offers, isOwner: true },
            global: { stubs },
        });

        await wrapper.get('.offers-new-button').trigger('click');
        replace.mockClear();
        await wrapper.get('[data-test="close-editor"]').trigger('click');

        expect(back).toHaveBeenCalledTimes(1);
        expect(replace).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('refreshes a saved offer after Inertia restores the base history entry', async () => {
        const saved = { ...offers[0], uuid: 'saved-offer' };
        const SavedEditor = defineComponent({
            props: ['open'],
            emits: ['update:open', 'saved'],
            setup(_props, { emit }) {
                return {
                    save: () => {
                        emit('saved', { offer: saved }, false);
                        emit('update:open', false);
                    },
                };
            },
            template:
                '<div v-if="open"><button data-test="save-offer" @click="save">Save</button></div>',
        });
        const back = vi
            .spyOn(window.history, 'back')
            .mockImplementation(() => undefined);
        const wrapper = mount(OffersIndex, {
            props: { offers: [], isOwner: true },
            global: {
                stubs: { ...stubs, OfferEditorDrawer: SavedEditor },
            },
        });

        await wrapper.get('.offers-new-button').trigger('click');
        await wrapper.get('[data-test="save-offer"]').trigger('click');

        expect(back).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-test="offer-list"]').text()).toContain(
            '1 offers',
        );
        expect(reload).not.toHaveBeenCalled();

        window.history.replaceState({}, '', '/offers');
        window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
        inertiaNavigate?.();

        expect(reload).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('closes an initial deep link in place without navigating away', async () => {
        window.history.replaceState({}, '', '/offers?panel=edit&offer=offer-1');
        const back = vi.spyOn(window.history, 'back');
        const wrapper = mount(OffersIndex, {
            props: { offers, isOwner: true },
            global: { stubs },
        });
        await flushPromises();

        expect(wrapper.get('[data-test="offer-editor"]').exists()).toBe(true);
        await wrapper.get('[data-test="close-editor"]').trigger('click');

        expect(back).not.toHaveBeenCalled();
        expect(window.location.pathname).toBe('/offers');
        expect(window.location.search).toBe('');
        wrapper.unmount();
    });

    it('holds browser Back on dirty edits until the owner chooses what to do', async () => {
        const DirtyEditor = defineComponent({
            props: ['open'],
            emits: ['dirty-change'],
            setup(_props, { emit, expose }) {
                const guarded = ref(false);
                let proceed: (() => void) | null = null;

                expose({
                    guardNavigation(action: () => void) {
                        guarded.value = true;
                        proceed = action;

                        return false;
                    },
                });

                return {
                    guarded,
                    markDirty: () => emit('dirty-change', true),
                    discard: () => proceed?.(),
                };
            },
            template:
                '<div v-if="open" data-test="dirty-editor"><button data-test="mark-dirty" @click="markDirty">Edit</button><button v-if="guarded" data-test="discard-navigation" @click="discard">Discard</button></div>',
        });
        const forward = vi
            .spyOn(window.history, 'forward')
            .mockImplementation(() => undefined);
        const wrapper = mount(OffersIndex, {
            props: { offers, isOwner: true },
            global: {
                stubs: { ...stubs, OfferEditorDrawer: DirtyEditor },
            },
        });

        await wrapper.get('.offers-new-button').trigger('click');
        await wrapper.get('[data-test="mark-dirty"]').trigger('click');

        // Emulate the URL/state observed by popstate after the browser moves
        // from the locally pushed overlay entry back to the base Offers URL.
        window.history.replaceState({}, '', '/offers');
        window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
        await flushPromises();

        expect(forward).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-test="dirty-editor"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="discard-navigation"]').exists()).toBe(
            true,
        );

        await wrapper.get('[data-test="discard-navigation"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="dirty-editor"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('holds ordinary Inertia link navigation for Cancel or Discard and replays it once', async () => {
        const guardNavigation = vi.fn();
        const DirtyEditor = defineComponent({
            props: ['open'],
            emits: ['dirty-change'],
            setup(_props, { emit, expose }) {
                const guarded = ref(false);
                let proceed: (() => void) | null = null;

                expose({
                    guardNavigation(action: () => void) {
                        guardNavigation();
                        guarded.value = true;
                        proceed = action;

                        return false;
                    },
                });

                return {
                    guarded,
                    markDirty: () => emit('dirty-change', true),
                    cancel: () => {
                        guarded.value = false;
                        proceed = null;
                    },
                    discard: () => {
                        guarded.value = false;
                        const next = proceed;
                        proceed = null;
                        next?.();
                    },
                };
            },
            template:
                '<div v-if="open" data-test="dirty-editor"><button data-test="mark-dirty" @click="markDirty">Edit</button><template v-if="guarded"><button data-test="cancel-navigation" @click="cancel">Cancel</button><button data-test="discard-navigation" @click="discard">Discard</button></template></div>',
        });
        const wrapper = mount(OffersIndex, {
            props: { offers, isOwner: true },
            global: {
                stubs: { ...stubs, OfferEditorDrawer: DirtyEditor },
            },
        });

        await wrapper.get('.offers-new-button').trigger('click');
        await wrapper.get('[data-test="mark-dirty"]').trigger('click');

        const navigate = (): CustomEvent => {
            const event = new CustomEvent('inertia:before', {
                cancelable: true,
                detail: {
                    visit: {
                        method: 'get',
                        url: new URL(
                            '/settings/gtm-profile',
                            window.location.origin,
                        ),
                    },
                },
            });
            inertiaBefore?.(event);

            return event;
        };

        const cancelledNavigation = navigate();
        await flushPromises();
        expect(cancelledNavigation.defaultPrevented).toBe(true);
        await wrapper.get('[data-test="cancel-navigation"]').trigger('click');
        expect(visit).not.toHaveBeenCalled();

        const discardedNavigation = navigate();
        await flushPromises();
        expect(discardedNavigation.defaultPrevented).toBe(true);
        visit.mockImplementationOnce(() => {
            // Inertia synchronously emits another before event for the replay.
            navigate();
        });
        await wrapper.get('[data-test="discard-navigation"]').trigger('click');
        await flushPromises();

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit).toHaveBeenCalledWith(
            `${window.location.origin}/settings/gtm-profile`,
        );
        expect(guardNavigation).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });

    it('announces a server-provided error without inventing fallback data', () => {
        const wrapper = mount(OffersIndex, {
            props: {
                offers: [],
                isOwner: true,
                error: 'Offers are temporarily unavailable.',
            },
            global: { stubs },
        });

        expect(wrapper.get('.offers-action-error').attributes('role')).toBe(
            'alert',
        );
        expect(wrapper.get('.offers-action-error').text()).toContain(
            'temporarily unavailable',
        );
        wrapper.unmount();
    });
});
