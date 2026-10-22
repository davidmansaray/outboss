import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const { patchRequest } = vi.hoisted(() => ({
    patchRequest: vi.fn(),
}));

vi.mock('@/lib/jsonClient', () => ({
    jsonClient: { patch: patchRequest },
    JsonHttpError: class JsonHttpError extends Error {
        status = 500;
        errors: Record<string, string> = {};
        currentLockVersion: number | null = null;
        currentSection: Record<string, unknown> | null = null;

        constructor(
            message: string,
            status = 500,
            errors: Record<string, string> = {},
            currentLockVersion: number | null = null,
            currentSection: Record<string, unknown> | null = null,
        ) {
            super(message);
            this.status = status;
            this.errors = errors;
            this.currentLockVersion = currentLockVersion;
            this.currentSection = currentSection;
        }
    },
}));

import { useGtmProfileAutosave } from '@/composables/useGtmProfileAutosave';
import { JsonHttpError } from '@/lib/jsonClient';

const Host = defineComponent({
    setup(_, { expose }) {
        const autosave = useGtmProfileAutosave({
            profileKey: 'profile-1',
            section: 'company',
            expectedLockVersion: 3,
            delayMs: 800,
        });
        expose(autosave);

        return autosave;
    },
    template: '<div />',
});

describe('useGtmProfileAutosave', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        patchRequest.mockReset();
    });

    it('debounces one section, stays unsaved until acknowledgement, and serializes changes', async () => {
        const pending: Array<{
            resolve: (value: unknown) => void;
            body: Record<string, unknown>;
        }> = [];
        patchRequest.mockImplementation(
            (_url: string, body: Record<string, unknown>) =>
                new Promise((resolve) => pending.push({ resolve, body })),
        );
        const wrapper = mount(Host);

        wrapper.vm.schedule({ name: 'first' });
        await vi.advanceTimersByTimeAsync(799);
        expect(patchRequest).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(patchRequest).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.status).toBe('saving');
        expect(wrapper.vm.status).not.toBe('saved');

        wrapper.vm.schedule({ name: 'second' });
        const first = pending.shift()!;
        first.resolve({ lock_version: 4 });
        await flushPromises();

        expect(patchRequest).toHaveBeenCalledTimes(2);
        expect(pending).toHaveLength(1);
        expect(pending[0].body.payload).toEqual({ name: 'second' });

        pending[0].resolve({ lock_version: 5 });
        await flushPromises();
        expect(wrapper.vm.status).toBe('saved');
        expect(wrapper.vm.hasPending).toBe(false);
        wrapper.unmount();
        vi.useRealTimers();
    });

    it('reuses the failed client operation when retrying after a transport error', async () => {
        patchRequest.mockRejectedValueOnce(new Error('offline'));
        const wrapper = mount(Host);

        wrapper.vm.schedule({ name: 'retry me' });
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();
        expect(wrapper.vm.status).toBe('error');

        const firstBody = patchRequest.mock.calls[0][1];
        patchRequest.mockResolvedValueOnce({ lock_version: 4 });
        await wrapper.vm.retry();
        await flushPromises();

        expect(patchRequest).toHaveBeenCalledTimes(2);
        expect(patchRequest.mock.calls[1][1].client_operation_id).toBe(
            firstBody.client_operation_id,
        );
        expect(wrapper.vm.status).toBe('saved');
        wrapper.unmount();
        vi.useRealTimers();
    });

    it('freezes a stale operation until the owner explicitly reviews and reapplies it', async () => {
        const conflict = new JsonHttpError(
            'stale profile',
            409,
            {
                message: 'This profile changed in another tab.',
            },
            5,
            { name: { value: 'Server company' } },
        );
        patchRequest.mockRejectedValueOnce(conflict);
        const wrapper = mount(Host);

        wrapper.vm.schedule({ name: 'conflicting edit' });
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(wrapper.vm.status).toBe('conflict');
        expect(wrapper.vm.conflict).toBe(conflict);
        expect(wrapper.vm.error).toBe('This profile changed in another tab.');
        expect(wrapper.vm.hasPending).toBe(true);

        const firstBody = patchRequest.mock.calls[0][1];
        expect(firstBody.expected_lock_version).toBe(3);
        await expect(wrapper.vm.retry()).resolves.toBe(false);
        expect(patchRequest).toHaveBeenCalledTimes(1);

        patchRequest.mockResolvedValueOnce({ lock_version: 6 });
        await wrapper.vm.reapplyConflict();
        await flushPromises();

        expect(patchRequest).toHaveBeenCalledTimes(2);
        expect(patchRequest.mock.calls[1][1].client_operation_id).not.toBe(
            firstBody.client_operation_id,
        );
        expect(patchRequest.mock.calls[1][1].expected_lock_version).toBe(5);
        expect(wrapper.vm.status).toBe('saved');
        expect(wrapper.vm.conflict).toBeNull();
        wrapper.unmount();
        vi.useRealTimers();
    });

    it('does not reapply a conflict when the response has no current lock', async () => {
        const conflict = new JsonHttpError('stale profile', 409, {
            message: 'Reload this profile before reapplying.',
        });
        patchRequest.mockRejectedValueOnce(conflict);
        const wrapper = mount(Host);

        wrapper.vm.schedule({ name: 'needs a rebase' });
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(wrapper.vm.status).toBe('conflict');
        expect(wrapper.vm.hasPending).toBe(true);

        await expect(wrapper.vm.retry()).resolves.toBe(false);
        await expect(wrapper.vm.reapplyConflict()).resolves.toBe(false);
        expect(patchRequest).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.status).toBe('conflict');

        wrapper.unmount();
        vi.useRealTimers();
    });
});
