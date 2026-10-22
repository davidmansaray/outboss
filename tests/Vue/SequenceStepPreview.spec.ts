import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SequenceStepPreview from '@/components/campaigns/SequenceStepPreview.vue';

function jsonResponse(body: unknown, ok = true, status = 200): Response {
    return {
        ok,
        status,
        json: async () => body,
    } as Response;
}

describe('Sequence step preview', () => {
    it('refreshes after saved copy changes and ignores an older in-flight response', async () => {
        let finishOld!: (response: Response) => void;
        let finishNew!: (response: Response) => void;
        const fetchMock = vi
            .fn()
            .mockReturnValueOnce(
                new Promise<Response>((resolve) => {
                    finishOld = resolve;
                }),
            )
            .mockReturnValueOnce(
                new Promise<Response>((resolve) => {
                    finishNew = resolve;
                }),
            );
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(SequenceStepPreview, {
            props: {
                stepId: 21,
                recipientId: 11,
                people: [],
                savedContentKey: 'old',
            },
        });
        await wrapper.setProps({ savedContentKey: 'new' });
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(wrapper.get('[role="status"]').text()).toContain(
            'Loading saved preview',
        );
        expect(wrapper.find('.sequence-preview-meta').exists()).toBe(false);
        finishNew(
            jsonResponse({
                subject: 'New saved subject',
                body: 'New saved body',
                recipient_id: 11,
            }),
        );
        await flushPromises();
        finishOld(
            jsonResponse({
                subject: 'Stale subject',
                body: 'Stale body',
                recipient_id: 11,
            }),
        );
        await flushPromises();
        expect(wrapper.text()).toContain('New saved subject');
        expect(wrapper.text()).not.toContain('Stale');
    });

    beforeEach(() => {
        vi.unstubAllGlobals();
    });

    it('renders fetched preview content', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            jsonResponse({
                subject: 'A question for Stripe',
                body: 'Hi Maya, would this help Stripe?',
                to: 'maya@example.test',
                recipient_id: 11,
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(SequenceStepPreview, {
            props: {
                stepId: 21,
                recipientId: 11,
                people: [
                    {
                        id: 11,
                        name: 'Maya Chen',
                        email: 'maya@example.test',
                    },
                ],
            },
        });

        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/sequence-steps/21/preview?recipient_id=11',
            expect.objectContaining({
                credentials: 'same-origin',
            }),
        );
        expect(wrapper.get('.sequence-preview').text()).toContain(
            'A question for Stripe',
        );
        expect(wrapper.get('.sequence-preview-body').text()).toContain(
            'Hi Maya, would this help Stripe?',
        );
        expect(wrapper.text()).toContain('enrolment snapshot');
    });

    it('shows a sample caption when no people are enrolled', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                jsonResponse({
                    subject: 'Hello Jane at Acme',
                    body: 'Hi Jane',
                    to: 'jane@example.com',
                    recipient_id: null,
                }),
            ),
        );

        const wrapper = mount(SequenceStepPreview, {
            props: {
                stepId: 21,
                recipientId: null,
                people: [],
            },
        });

        await flushPromises();

        expect(wrapper.text()).toContain(
            'Sample data — add people to preview a real recipient.',
        );
    });

    it('renders a 422 message as the pane error', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    jsonResponse(
                        { message: 'Unknown variable {{last_name}}' },
                        false,
                        422,
                    ),
                ),
        );

        const wrapper = mount(SequenceStepPreview, {
            props: {
                stepId: 21,
                recipientId: null,
                people: [],
            },
        });

        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain(
            'Unknown variable {{last_name}}',
        );
    });
});
