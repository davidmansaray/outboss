import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import EmailFrame from '@/components/EmailFrame.vue';

describe('EmailFrame', () => {
    it('uses a compact transparent canvas around email content', () => {
        const wrapper = mount(EmailFrame, {
            props: { html: '<p>Hello there</p>' },
        });
        const frame = wrapper.get('iframe');
        const source = frame.attributes('srcdoc');

        expect(frame.attributes('style')).toContain('height: 32px');
        expect(source).toContain('background:transparent');
        expect(source).toContain('body>:first-child{margin-top:0;}');
        expect(source).toContain('body>:last-child{margin-bottom:0;}');
    });
});
