import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import OfferStatusMenu from '@/components/offers/OfferStatusMenu.vue';

const stubs = {
    DropdownMenu: { template: '<div class="dropdown-menu"><slot /></div>' },
    DropdownMenuContent: {
        template: '<div class="dropdown-content"><slot /></div>',
    },
    DropdownMenuItem: {
        props: { disabled: Boolean },
        emits: ['select'],
        template:
            '<button class="dropdown-item" :disabled="disabled" @click="$emit(\'select\')"><slot /></button>',
    },
    DropdownMenuLabel: { template: '<span><slot /></span>' },
    DropdownMenuSeparator: { template: '<hr />' },
    DropdownMenuTrigger: {
        template: '<div class="dropdown-trigger"><slot /></div>',
    },
};

describe('OfferStatusMenu', () => {
    it('exposes only legal owner transitions and emits a selected status', async () => {
        const wrapper = mount(OfferStatusMenu, {
            props: { status: 'draft', isOwner: true },
            global: { stubs },
        });

        expect(wrapper.get('.offer-status-trigger').text()).toContain('Draft');
        const options = wrapper.findAll('.dropdown-item');
        expect(options).toHaveLength(4);
        expect(options[0].attributes('disabled')).toBeDefined();
        expect(options[1].attributes('disabled')).toBeUndefined();
        expect(options[2].attributes('disabled')).toBeDefined();
        expect(options[3].attributes('disabled')).toBeDefined();

        await options[1].trigger('click');
        expect(wrapper.emitted('change')).toEqual([['active']]);

        wrapper.unmount();
    });

    it('allows active to pause but not to return to draft', async () => {
        const wrapper = mount(OfferStatusMenu, {
            props: { status: 'active', isOwner: true },
            global: { stubs },
        });

        const options = wrapper.findAll('.dropdown-item');
        expect(options[0].attributes('disabled')).toBeDefined();
        expect(options[1].attributes('disabled')).toBeDefined();
        expect(options[2].attributes('disabled')).toBeUndefined();
        expect(options[3].attributes('disabled')).toBeDefined();

        await options[2].trigger('click');
        expect(wrapper.emitted('change')).toEqual([['paused']]);
        wrapper.unmount();
    });

    it('renders a read-only status badge for members', () => {
        const wrapper = mount(OfferStatusMenu, {
            props: { status: 'paused', isOwner: false },
            global: { stubs },
        });

        expect(wrapper.get('.offer-status-badge').text()).toContain('Paused');
        expect(wrapper.find('.offer-status-trigger').exists()).toBe(false);
        expect(wrapper.findAll('.dropdown-item')).toHaveLength(0);
        wrapper.unmount();
    });
});
