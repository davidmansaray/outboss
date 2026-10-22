import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { flushEditor, getRequest, inertia, polling, reload, visit } = vi.hoisted(
    () => ({
        flushEditor: vi.fn(),
        getRequest: vi.fn(),
        inertia: { before: null as ((event: Event) => unknown) | null },
        polling: {
            onTerminal: null as
                ((...args: never[]) => void | Promise<void>) | null,
            retryTerminal: vi.fn(),
        },
        reload: vi.fn(),
        visit: vi.fn(),
    }),
);

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: {
        reload,
        visit,
        on: vi.fn((name: string, callback: (event: Event) => unknown) => {
            if (name === 'before') {
                inertia.before = callback;
            }

            return () => {
                inertia.before = null;
            };
        }),
    },
    usePage: () => ({ props: { auth: { workspace: { role: 'owner' } } } }),
}));

vi.mock('@/composables/useGtmProfileResearchPolling', () => ({
    useGtmProfileResearchPolling: vi.fn(
        (
            _source: unknown,
            options?: {
                onTerminal?: (...args: never[]) => void | Promise<void>;
            },
        ) => {
            polling.onTerminal = options?.onTerminal ?? null;

            return {
                busy: false,
                isPolling: false,
                isHandlingTerminal: { value: false },
                retryTerminal: polling.retryTerminal,
                start: vi.fn(),
                stop: vi.fn(),
                terminalRefreshNeeded: { value: false },
            };
        },
    ),
}));

vi.mock('@/lib/jsonClient', () => ({
    jsonClient: { get: getRequest, post: vi.fn() },
    JsonHttpError: class JsonHttpError extends Error {
        errors: Record<string, string> = {};
    },
}));

import GtmProfileShow from '@/pages/gtm-profile/Show.vue';
import type { GtmProfile } from '@/types/gtmProfile';

const profile: GtmProfile = {
    uuid: 'profile-1',
    lock_version: 1,
    payload: { company: { name: 'Acme' } },
};

const stubs = {
    AppWorkspace: {
        props: ['area', 'title'],
        template:
            '<div class="app" :data-area="area"><aside><slot name="sidebar" /></aside><div class="top"><slot name="actions" /></div><slot /></div>',
    },
    WorkspaceOpsSidebar: { template: '<div />' },
    GtmProfileOverview: {
        emits: ['sources', 'edit', 'review'],
        template:
            '<main data-test="overview"><button data-test="open-overview-sources" @click="$emit(\'sources\')">Sources</button><button data-test="open-overview-editor" @click="$emit(\'edit\', \'company\')">Edit</button><button data-test="open-overview-review" @click="$emit(\'review\')">Review</button></main>',
    },
    GtmProfileEditorDrawer: defineComponent({
        props: ['open'],
        setup(_, { expose }) {
            expose({
                flush: flushEditor,
                hasPending: true,
                discard: vi.fn(),
            });

            return {};
        },
        template: '<div v-if="open" data-test="profile-editor" />',
    }),
    GtmProfileSourcesDrawer: {
        props: ['open'],
        emits: ['update:open'],
        template:
            '<div v-if="open" data-test="profile-sources"><button data-test="close-profile-sources" @click="$emit(\'update:open\', false)">Close</button></div>',
    },
    GtmProfileHistoryDrawer: {
        props: ['open'],
        template: '<div v-if="open" data-test="profile-history" />',
    },
    GtmProfileReviewDialog: {
        props: ['open'],
        emits: ['edit', 'update:open'],
        template:
            '<div v-if="open" data-test="profile-review"><button data-test="review-edit" @click="$emit(\'edit\', \'buyers\')">Edit mine</button></div>',
    },
    DropdownMenu: { template: '<div><slot /></div>' },
    DropdownMenuContent: {
        template: '<div class="gtm-overflow-menu"><slot /></div>',
    },
    DropdownMenuItem: {
        emits: ['select'],
        template: '<button @click="$emit(\'select\')"><slot /></button>',
    },
    DropdownMenuLabel: { template: '<span><slot /></span>' },
    DropdownMenuSeparator: { template: '<hr />' },
    DropdownMenuTrigger: { template: '<span><slot /></span>' },
};

describe('GTM profile page', () => {
    beforeEach(() => {
        flushEditor.mockReset();
        flushEditor.mockResolvedValue(true);
        reload.mockReset();
        reload.mockImplementation(
            (options?: { onSuccess?: () => void; onFinish?: () => void }) => {
                options?.onSuccess?.();
                options?.onFinish?.();
            },
        );
        visit.mockReset();
        getRequest.mockReset();
        getRequest.mockImplementation((url: string) =>
            Promise.resolve(
                url.includes('/history')
                    ? { history: [], has_more: false, next_cursor: null }
                    : { sources: [], source_count: 0 },
            ),
        );
        polling.onTerminal = null;
        polling.retryTerminal.mockReset();
        window.history.replaceState({}, '', '/settings/gtm-profile');
    });

    it('uses the settings shell and keeps overlay state in the URL', async () => {
        const wrapper = mount(GtmProfileShow, {
            props: { profile, isOwner: true },
            global: { stubs },
        });

        expect(wrapper.get('.app').attributes('data-area')).toBe('gtm-profile');
        await wrapper
            .get('[data-test="open-overview-sources"]')
            .trigger('click');
        await flushPromises();
        expect(window.location.search).toContain('panel=sources');
        expect(wrapper.get('[data-test="profile-sources"]').exists()).toBe(
            true,
        );

        window.history.replaceState({}, '', '/settings/gtm-profile');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-test="profile-sources"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });

    it('loads source and history details only when their panels open', async () => {
        const wrapper = mount(GtmProfileShow, {
            props: { profile, isOwner: true },
            global: { stubs },
        });

        expect(getRequest).not.toHaveBeenCalled();
        await wrapper
            .get('[data-test="open-overview-sources"]')
            .trigger('click');
        await flushPromises();
        expect(getRequest).toHaveBeenCalledWith(
            '/settings/gtm-profile/sources',
        );

        window.history.replaceState({}, '', '/settings/gtm-profile');
        window.dispatchEvent(new PopStateEvent('popstate'));
        await wrapper.vm.$nextTick();
        const historyButton = wrapper
            .findAll('.gtm-overflow-menu button')
            .find((button) => button.text().includes('View history'))!;
        await historyButton.trigger('click');
        await flushPromises();
        expect(getRequest).toHaveBeenCalledWith(
            '/settings/gtm-profile/history?per_page=20',
        );

        wrapper.unmount();
    });

    it('consumes its pushed overlay entry so the next Back leaves the base page', async () => {
        window.history.replaceState({}, '', '/settings?from=gtm-profile');
        window.history.pushState({}, '', '/settings/gtm-profile');
        const wrapper = mount(GtmProfileShow, {
            props: { profile, isOwner: true },
            global: { stubs },
        });

        await wrapper
            .get('[data-test="open-overview-sources"]')
            .trigger('click');
        expect(window.location.search).toContain('panel=sources');

        await wrapper
            .get('[data-test="close-profile-sources"]')
            .trigger('click');
        await vi.waitFor(() => {
            expect(window.location.pathname).toBe('/settings/gtm-profile');
            expect(window.location.search).toBe('');
            expect(wrapper.find('[data-test="profile-sources"]').exists()).toBe(
                false,
            );
        });

        window.history.back();
        await vi.waitFor(() => {
            expect(window.location.pathname).toBe('/settings');
            expect(window.location.search).toBe('?from=gtm-profile');
        });

        wrapper.unmount();
    });

    it('replaces a directly loaded overlay URL when it closes', async () => {
        window.history.replaceState(
            {},
            '',
            '/settings/gtm-profile?panel=sources',
        );
        const wrapper = mount(GtmProfileShow, {
            props: { profile, isOwner: true },
            global: { stubs },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-test="profile-sources"]').exists()).toBe(
            true,
        );
        await wrapper
            .get('[data-test="close-profile-sources"]')
            .trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/settings/gtm-profile');
        expect(window.location.search).toBe('');
        expect(wrapper.find('[data-test="profile-sources"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });

    it('exposes sources, history, export, and research actions in the overflow menu', async () => {
        const wrapper = mount(GtmProfileShow, {
            props: { profile, isOwner: true },
            global: { stubs },
        });

        expect(wrapper.get('.gtm-overflow-menu').text()).toContain(
            'View sources',
        );
        expect(
            wrapper.get('a[href="/settings/gtm-profile/export"]').text(),
        ).toContain('Export JSON');
        await wrapper.get('.gtm-overflow-menu button').trigger('click');
        expect(window.location.search).toContain('panel=sources');
        wrapper.unmount();
    });

    it('atomically replaces review with the requested editor section', async () => {
        const wrapper = mount(GtmProfileShow, {
            props: { profile, isOwner: true },
            global: { stubs },
        });

        await wrapper
            .get('[data-test="open-overview-review"]')
            .trigger('click');
        await wrapper.get('[data-test="review-edit"]').trigger('click');

        expect(window.location.search).toContain('panel=edit');
        expect(window.location.search).toContain('section=buyers');
        expect(wrapper.find('[data-test="profile-review"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="profile-editor"]').exists()).toBe(
            true,
        );
        wrapper.unmount();
    });

    it('keeps the editor open when a panel transition cannot flush autosave', async () => {
        flushEditor.mockResolvedValue(false);
        window.history.replaceState(
            {},
            '',
            '/settings/gtm-profile?panel=edit&section=company',
        );
        const wrapper = mount(GtmProfileShow, {
            props: { profile, isOwner: true },
            global: { stubs },
        });

        await wrapper.vm.$nextTick();
        await wrapper
            .get('[data-test="open-overview-sources"]')
            .trigger('click');
        await Promise.resolve();

        expect(flushEditor).toHaveBeenCalledTimes(1);
        expect(window.location.search).toContain('panel=edit');
        expect(wrapper.find('[data-test="profile-editor"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-test="profile-sources"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });

    it('cancels arbitrary Inertia navigation when a dirty editor cannot flush', async () => {
        flushEditor.mockResolvedValue(false);
        window.history.replaceState(
            {},
            '',
            '/settings/gtm-profile?panel=edit&section=company',
        );
        const wrapper = mount(GtmProfileShow, {
            props: { profile, isOwner: true },
            global: { stubs },
        });
        await wrapper.vm.$nextTick();

        const event = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: {
                    url: new URL('/offers', window.location.origin),
                    method: 'get',
                    only: [],
                },
            },
        });
        inertia.before?.(event);
        await flushPromises();

        expect(event.defaultPrevented).toBe(true);
        expect(flushEditor).toHaveBeenCalledTimes(1);
        expect(visit).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="profile-editor"]').exists()).toBe(
            true,
        );
        wrapper.unmount();
    });

    it('defers a terminal research refresh when the autosave queue needs a decision', async () => {
        flushEditor.mockResolvedValue(false);
        window.history.replaceState(
            {},
            '',
            '/settings/gtm-profile?panel=edit&section=company',
        );
        const wrapper = mount(GtmProfileShow, {
            props: {
                profile,
                isOwner: true,
                researchState: { state: 'running', run_uuid: 'run-1' },
            },
            global: { stubs },
        });
        await wrapper.vm.$nextTick();

        await expect(polling.onTerminal?.()).rejects.toThrow(
            'profile editor refresh blocked',
        );
        await flushPromises();

        expect(flushEditor).toHaveBeenCalledTimes(1);
        expect(reload).not.toHaveBeenCalled();
        expect(
            wrapper.get('[data-test="gtm-terminal-refresh-blocked"]').text(),
        ).toContain('Research is complete');
        expect(
            wrapper.get('[data-test="gtm-terminal-refresh-retry"]').exists(),
        ).toBe(true);
        wrapper.unmount();
    });

    it('keeps a terminal refresh retryable when Inertia errors before onFinish', async () => {
        reload.mockImplementationOnce(
            (options?: {
                onError?: (errors: Record<string, string>) => void;
                onFinish?: () => void;
            }) => {
                options?.onError?.({ profile: 'Unavailable' });
                options?.onFinish?.();
            },
        );
        const wrapper = mount(GtmProfileShow, {
            props: {
                profile,
                isOwner: true,
                researchState: { state: 'running', run_uuid: 'run-1' },
            },
            global: { stubs },
        });
        await wrapper.vm.$nextTick();

        await expect(polling.onTerminal?.()).rejects.toThrow(
            'profile refresh failed',
        );
        await flushPromises();

        expect(
            wrapper.get('[data-test="gtm-terminal-refresh-blocked"]').text(),
        ).toContain('latest profile could not be loaded');
        expect(
            wrapper.get('[data-test="gtm-terminal-refresh-retry"]').exists(),
        ).toBe(true);

        polling.retryTerminal.mockImplementationOnce(async () => {
            await polling.onTerminal?.();

            return true;
        });
        await wrapper
            .get('[data-test="gtm-terminal-refresh-retry"]')
            .trigger('click');
        await flushPromises();

        expect(reload).toHaveBeenCalledTimes(2);
        expect(
            wrapper.find('[data-test="gtm-terminal-refresh-blocked"]').exists(),
        ).toBe(false);
        wrapper.unmount();
    });

    it('does not treat a cancelled terminal refresh onFinish as success', async () => {
        reload.mockImplementationOnce(
            (options?: { onCancel?: () => void; onFinish?: () => void }) => {
                options?.onCancel?.();
                options?.onFinish?.();
            },
        );
        const wrapper = mount(GtmProfileShow, {
            props: {
                profile,
                isOwner: true,
                researchState: { state: 'running', run_uuid: 'run-1' },
            },
            global: { stubs },
        });
        await wrapper.vm.$nextTick();

        await expect(polling.onTerminal?.()).rejects.toThrow(
            'profile refresh was cancelled',
        );
        await flushPromises();

        expect(
            wrapper.get('[data-test="gtm-terminal-refresh-retry"]').exists(),
        ).toBe(true);
        expect(
            wrapper.get('[data-test="gtm-terminal-refresh-blocked"]').text(),
        ).toContain('completed research is preserved');
        wrapper.unmount();
    });
});
