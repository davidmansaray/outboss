import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import WorkspaceAvatar from '@/components/WorkspaceAvatar.vue';

describe('WorkspaceAvatar', () => {
    it('renders a cached favicon when one is available', () => {
        const wrapper = mount(WorkspaceAvatar, {
            props: { name: 'Checkout Watch', src: '/storage/workspace-avatars/1/icon.png' },
        });

        expect(wrapper.get('img').attributes('src')).toBe('/storage/workspace-avatars/1/icon.png');
        expect(wrapper.find('.workspace-avatar-fallback').exists()).toBe(false);
    });

    it('uses the first initial when no favicon is available or loading fails', async () => {
        const wrapper = mount(WorkspaceAvatar, {
            props: { name: '  formzy', src: null },
        });

        expect(wrapper.get('.workspace-avatar-fallback').text()).toBe('F');

        await wrapper.setProps({ src: '/storage/broken.png' });
        await wrapper.get('img').trigger('error');

        expect(wrapper.get('.workspace-avatar-fallback').text()).toBe('F');
        expect(wrapper.find('img').exists()).toBe(false);
    });

    it('tries a new favicon after the source changes', async () => {
        const wrapper = mount(WorkspaceAvatar, {
            props: { name: 'Outboss', src: '/storage/old.png' },
        });
        await wrapper.get('img').trigger('error');
        await wrapper.setProps({ src: '/storage/new.png' });

        expect(wrapper.get('img').attributes('src')).toBe('/storage/new.png');
    });
});
