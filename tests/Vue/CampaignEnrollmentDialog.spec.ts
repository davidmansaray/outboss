import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { router } = vi.hoisted(() => ({
    router: {
        post: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router,
}));

import CampaignEnrollmentDialog from '@/components/campaigns/CampaignEnrollmentDialog.vue';

const eligible = {
    id: 11,
    name: 'Maya Chen',
    email: 'maya@stripe.com',
    account: 'Stripe',
    eligibility: 'Eligible' as const,
    enrollable: true,
    reason: null,
};

const suppressed = {
    id: 12,
    name: 'Gone Person',
    email: 'gone@acme.test',
    account: null,
    eligibility: 'Suppressed' as const,
    enrollable: false,
    reason: 'Suppressed',
};

const already = {
    id: 13,
    name: 'Already In',
    email: 'in@acme.test',
    account: 'Acme',
    eligibility: 'Eligible' as const,
    enrollable: false,
    reason: 'Already in campaign',
};

function jsonResponse(body: unknown, ok = true): Response {
    return {
        ok,
        json: async () => body,
    } as Response;
}

describe('Campaign enrollment dialog', () => {
    beforeEach(() => {
        router.post.mockReset();
        vi.unstubAllGlobals();
    });

    it('renders disabled reasons and submits selected enrollable people', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            jsonResponse({
                data: [eligible, suppressed, already],
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 25,
                    total: 3,
                },
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(CampaignEnrollmentDialog, {
            props: { campaignId: 7 },
        });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/campaigns/7/enrollable-people?page=1',
            expect.objectContaining({
                credentials: 'same-origin',
            }),
        );
        expect(wrapper.text()).toContain('Maya Chen');
        expect(wrapper.text()).toContain('Suppressed');
        expect(wrapper.text()).toContain('Already in campaign');

        const boxes = wrapper.findAll('.enrollment-select');
        expect((boxes[0].element as HTMLInputElement).disabled).toBe(false);
        expect((boxes[1].element as HTMLInputElement).disabled).toBe(true);
        expect((boxes[2].element as HTMLInputElement).disabled).toBe(true);

        await boxes[1].setValue(true);
        expect(wrapper.text()).toContain('0 selected');

        await boxes[0].setValue(true);
        expect(wrapper.text()).toContain('1 selected');

        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Add 1'))!
            .trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/campaigns/7/enrollments',
            { person_ids: [11] },
            expect.objectContaining({
                onSuccess: expect.any(Function),
            }),
        );
    });

    it('sends the eligibility filter and search query', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockResolvedValue(
            jsonResponse({
                data: [],
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 25,
                    total: 0,
                },
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(CampaignEnrollmentDialog, {
            props: { campaignId: 7 },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Import or create people first');

        await wrapper.get('#enrollment-search').setValue('maya');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/campaigns/7/enrollable-people?page=1&query=maya',
            expect.any(Object),
        );

        await wrapper.get('.enrollment-eligible-filter').setValue(true);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/campaigns/7/enrollable-people?page=1&query=maya&only_eligible=1',
            expect.any(Object),
        );

        expect(wrapper.text()).toContain('No people match this search.');
        vi.useRealTimers();
    });

    it('shows an actionable enrollment error without leaving the dialog', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                jsonResponse({
                    data: [eligible],
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        per_page: 25,
                        total: 1,
                    },
                }),
            ),
        );
        const wrapper = mount(CampaignEnrollmentDialog, {
            props: { campaignId: 7 },
        });
        await flushPromises();
        await wrapper.get('.enrollment-select').setValue(true);
        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Add 1'))!
            .trigger('click');

        const options = router.post.mock.calls[0][2];
        options.onError({
            person_ids:
                'Assign a mailbox from this workspace before importing recipients.',
        });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe(
            'Assign a mailbox from this workspace before importing recipients.',
        );
        expect(wrapper.text()).toContain('1 selected');
    });

    it('keeps selections across pages and searches and submits them together', async () => {
        vi.useFakeTimers();
        const other = { ...eligible, id: 21, name: 'Other person' };
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(
                jsonResponse({
                    data: [eligible],
                    meta: {
                        current_page: 1,
                        last_page: 2,
                        per_page: 1,
                        total: 2,
                    },
                }),
            )
            .mockResolvedValueOnce(
                jsonResponse({
                    data: [other],
                    meta: {
                        current_page: 2,
                        last_page: 2,
                        per_page: 1,
                        total: 2,
                    },
                }),
            )
            .mockResolvedValue(
                jsonResponse({
                    data: [],
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        per_page: 1,
                        total: 0,
                    },
                }),
            );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CampaignEnrollmentDialog, {
            props: { campaignId: 7 },
        });
        await flushPromises();
        await wrapper.get('.enrollment-select').setValue(true);
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Next')!
            .trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('1 selected');
        await wrapper.get('.enrollment-select').setValue(true);
        await wrapper.get('#enrollment-search').setValue('no match');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        expect(wrapper.text()).toContain(
            '2 selected across all pages and searches',
        );
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Add 2 people')!
            .trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/campaigns/7/enrollments',
            { person_ids: [11, 21] },
            expect.any(Object),
        );
        vi.useRealTimers();
    });

    it('blocks duplicate submissions and allows retry after an error', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    jsonResponse({
                        data: [eligible],
                        meta: {
                            current_page: 1,
                            last_page: 1,
                            per_page: 25,
                            total: 1,
                        },
                    }),
                ),
        );
        const wrapper = mount(CampaignEnrollmentDialog, {
            props: { campaignId: 7 },
        });
        await flushPromises();
        await wrapper.get('.enrollment-select').setValue(true);
        const button = wrapper
            .findAll('button')
            .find((item) => item.text() === 'Add 1 person')!;
        await button.trigger('click');
        await button.trigger('click');
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.text()).toBe('Adding…');
        const options = router.post.mock.calls[0][2];
        options.onError({ person_ids: 'Please try again.' });
        options.onFinish();
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toBe('Please try again.');
        expect(button.attributes('disabled')).toBeUndefined();
        await button.trigger('click');
        expect(router.post).toHaveBeenCalledTimes(2);
    });
});
