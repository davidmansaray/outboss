import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';

const { reload, terminalTransition } = vi.hoisted(() => ({
    reload: vi.fn(),
    terminalTransition: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({ router: { reload } }));

import { useGtmProfileResearchPolling } from '@/composables/useGtmProfileResearchPolling';
import type { GtmResearchState } from '@/types/gtmProfile';

const Host = defineComponent({
    props: {
        state: { type: Object, required: true },
    },
    setup(props, { expose }) {
        const polling = useGtmProfileResearchPolling(
            () => props.state as GtmResearchState,
            {
                pollMs: 1000,
                capMs: 4000,
                onTerminal: terminalTransition,
            },
        );
        expose(polling);

        return polling;
    },
    template: '<div />',
});

const running: GtmResearchState = { state: 'running', run_uuid: 'run-1' };
const ready: GtmResearchState = { state: 'ready', run_uuid: 'run-1' };

function finishReload(index = reload.mock.calls.length - 1): void {
    const options = reload.mock.calls[index]?.[0] as
        { onFinish?: () => void } | undefined;
    options?.onFinish?.();
}

describe('useGtmProfileResearchPolling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        reload.mockReset();
        terminalTransition.mockReset();
        terminalTransition.mockResolvedValue(undefined);
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'visible',
        });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('keeps one reload in flight, pauses while hidden, and handles one terminal transition', async () => {
        const wrapper = mount(Host, { props: { state: running } });

        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['researchState'],
                preserveUrl: true,
            }),
        );
        expect(wrapper.vm.inFlight).toBe(true);

        await vi.advanceTimersByTimeAsync(500);
        expect(reload).toHaveBeenCalledTimes(1);

        finishReload();
        await vi.advanceTimersByTimeAsync(1000);
        expect(reload).toHaveBeenCalledTimes(2);

        Object.defineProperty(document, 'visibilityState', { value: 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
        finishReload();
        await vi.advanceTimersByTimeAsync(2000);
        expect(reload).toHaveBeenCalledTimes(2);

        Object.defineProperty(document, 'visibilityState', {
            value: 'visible',
        });
        document.dispatchEvent(new Event('visibilitychange'));
        await nextTick();
        expect(reload).toHaveBeenCalledTimes(3);

        await wrapper.setProps({ state: ready });
        await nextTick();
        expect(terminalTransition).toHaveBeenCalledTimes(1);
        expect(terminalTransition).toHaveBeenCalledWith('ready', 'running');

        finishReload();
        await vi.advanceTimersByTimeAsync(3000);
        expect(reload).toHaveBeenCalledTimes(3);
        expect(wrapper.vm.isPolling).toBe(false);
        wrapper.unmount();
    });

    it('enforces a bounded polling window', async () => {
        const wrapper = mount(Host, { props: { state: running } });

        for (let index = 0; index < 4; index += 1) {
            finishReload();
            await vi.advanceTimersByTimeAsync(1000);
        }

        expect(wrapper.vm.timedOut).toBe(true);
        expect(wrapper.vm.isPolling).toBe(false);
        const countAtCap = reload.mock.calls.length;
        await vi.advanceTimersByTimeAsync(4000);
        expect(reload).toHaveBeenCalledTimes(countAtCap);
        wrapper.unmount();
    });

    it('retains and retries a terminal transition when its refresh fails', async () => {
        terminalTransition.mockRejectedValueOnce(new Error('reload failed'));
        const wrapper = mount(Host, { props: { state: running } });

        await wrapper.setProps({ state: ready });
        await flushPromises();

        expect(terminalTransition).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.terminalRefreshNeeded).toBe(true);

        terminalTransition.mockResolvedValueOnce(undefined);
        await wrapper.vm.retryTerminal();
        await flushPromises();

        expect(terminalTransition).toHaveBeenCalledTimes(2);
        expect(wrapper.vm.terminalRefreshNeeded).toBe(false);
        wrapper.unmount();
    });
});
