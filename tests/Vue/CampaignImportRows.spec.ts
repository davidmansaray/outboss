import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CampaignImportRows from '@/components/campaigns/CampaignImportRows.vue';

const response = (rows: unknown[], page = 1, last = 1) => ({
    ok: true,
    json: async () => ({
        data: rows,
        meta: { current_page: page, last_page: last, total: rows.length },
    }),
});

afterEach(() => vi.unstubAllGlobals());

describe('Mapped campaign import rows', () => {
    it('shows mapped values and personalization fallbacks, then filters results', async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValue(
                response([
                    {
                        row_number: 2,
                        email: 'qa@example.com',
                        first_name: null,
                        company: null,
                        issue: 'ready',
                    },
                ]),
            );
        vi.stubGlobal('fetch', fetcher);
        const wrapper = mount(CampaignImportRows, {
            props: { campaignId: 13, importId: 4 },
        });
        await flushPromises();
        expect(wrapper.text()).toContain('qa@example.com');
        expect(wrapper.text()).toContain('there (fallback)');
        expect(wrapper.text()).toContain('your team (fallback)');
        await wrapper.get('select').setValue('invalid');
        await flushPromises();
        expect(fetcher).toHaveBeenLastCalledWith(
            '/campaigns/13/recipient-imports/4/rows?page=1&issue=invalid',
            expect.any(Object),
        );
        wrapper.unmount();
    });

    it('provides an explicit retry after a failed request', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockRejectedValueOnce(new Error('offline'))
                .mockResolvedValue(response([])),
        );
        const wrapper = mount(CampaignImportRows, {
            props: { campaignId: 13, importId: 4 },
        });
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain(
            'Could not load',
        );
        await wrapper.get('[role="alert"] button').trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No rows match');
        wrapper.unmount();
    });
});
