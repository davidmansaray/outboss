import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { getRequest } = vi.hoisted(() => ({
    getRequest: vi.fn(),
}));

vi.mock('@/lib/jsonClient', () => ({
    jsonClient: { get: getRequest },
    JsonHttpError: class JsonHttpError extends Error {
        errors: Record<string, string> = {};
    },
}));

import OfferHistoryDrawer from '@/components/offers/OfferHistoryDrawer.vue';
import type { Offer, OfferVersion } from '@/types/offers';

const offer: Offer = {
    uuid: 'offer-1',
    name: 'Pipeline sprint',
    promise: '',
    target_audience: '',
    desired_outcome: '',
    call_to_action: '',
    notes: '',
    status: 'draft',
    lock_version: 3,
    version_count: 3,
};

function version(number: number): OfferVersion {
    return {
        uuid: `version-${number}`,
        version: number,
        status_snapshot: 'draft',
        origin: 'owner_edit',
    };
}

const stubs = {
    Sheet: {
        props: ['open'],
        template: '<div v-if="open"><slot /></div>',
    },
    SheetContent: { template: '<div><slot /></div>' },
    SheetDescription: { template: '<p><slot /></p>' },
    SheetHeader: { template: '<header><slot /></header>' },
    SheetTitle: { template: '<h2><slot /></h2>' },
};

describe('OfferHistoryDrawer', () => {
    beforeEach(() => getRequest.mockReset());

    it('fetches history only when opened and follows the opaque next cursor', async () => {
        getRequest
            .mockResolvedValueOnce({
                history: [version(3), version(2)],
                pagination: {
                    next_cursor: 'opaque-cursor',
                    has_more: true,
                    per_page: 20,
                },
            })
            .mockResolvedValueOnce({
                history: [version(1)],
                pagination: {
                    next_cursor: null,
                    has_more: false,
                    per_page: 20,
                },
            });

        const wrapper = mount(OfferHistoryDrawer, {
            props: { open: false, offer },
            global: { stubs },
        });
        expect(getRequest).not.toHaveBeenCalled();

        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(getRequest).toHaveBeenCalledTimes(1);
        expect(getRequest.mock.calls[0][0]).toBe(
            '/offers/offer-1/history?per_page=20',
        );
        expect(wrapper.text()).toContain('Version 3');
        expect(wrapper.text()).toContain('Showing 2 of 3 versions');

        await wrapper.get('.offer-history-more').trigger('click');
        await flushPromises();

        expect(getRequest).toHaveBeenCalledTimes(2);
        expect(getRequest.mock.calls[1][0]).toContain('cursor=opaque-cursor');
        expect(wrapper.text()).toContain('Version 1');
        expect(wrapper.find('.offer-history-more').exists()).toBe(false);
        wrapper.unmount();
    });
});
