import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';

const LinkStub = {
    name: 'Link',
    props: ['href', 'method', 'as', 'data', 'disabled', 'ariaLabel'],
    emits: ['start', 'finish', 'cancel', 'click'],
    template: `
        <a
            :href="href"
            :aria-label="ariaLabel"
            :aria-disabled="disabled ? 'true' : undefined"
            @click="$emit('click')"
        ><slot /></a>
    `,
};

const stubs = {
    Link: LinkStub,
    CreateWorkspaceDialog: { template: '<div data-test="create-dialog" />' },
    DropdownMenu: { template: '<div><slot /></div>' },
    DropdownMenuContent: { template: '<div><slot /></div>' },
    DropdownMenuGroup: { template: '<div><slot /></div>' },
    DropdownMenuLabel: { template: '<div><slot /></div>' },
    DropdownMenuTrigger: { template: '<div><slot /></div>' },
    DropdownMenuItem: {
        props: ['disabled', 'asChild'],
        emits: ['select'],
        template:
            '<div :aria-disabled="disabled ? \'true\' : undefined"><slot /></div>',
    },
};

const longWorkspaceName =
    'North America Enterprise Revenue Operations Workspace';

const page = {
    props: {
        auth: {
            user: {
                id: 1,
                name: 'David Mansaray',
                email: 'david@outboss.test',
            },
            workspace: {
                id: 10,
                name: 'Outboss',
                type: 'team',
                role: 'owner',
                avatar_url: '/storage/workspace-avatars/10/outboss.png',
            },
            workspaces: [
                {
                    id: 11,
                    name: longWorkspaceName,
                    type: 'team',
                    role: 'member',
                    avatar_url: null,
                },
                {
                    id: 10,
                    name: 'Outboss',
                    type: 'team',
                    role: 'owner',
                    avatar_url: '/storage/workspace-avatars/10/outboss.png',
                },
            ],
        },
    },
};

function mountSwitcher() {
    return mount(WorkspaceSwitcher, {
        global: {
            stubs,
            config: { globalProperties: { $page: page } },
        },
    });
}

describe('WorkspaceSwitcher', () => {
    it('shows the current favicon and a first-initial fallback in the menu', () => {
        const wrapper = mountSwitcher();

        expect(wrapper.get('.workspace-control img').attributes('src')).toBe(
            '/storage/workspace-avatars/10/outboss.png',
        );
        expect(
            wrapper.get('.workspace-switcher-row:not(.current) .workspace-avatar-fallback').text(),
        ).toBe('N');
    });

    it('presents the current workspace as available and clearly identified', () => {
        const wrapper = mountSwitcher();
        const currentRow = wrapper.get('.workspace-switcher-row.current');
        const currentItem = currentRow.get('.workspace-switcher-item');

        expect(currentItem.attributes('aria-current')).toBe('true');
        expect(currentItem.attributes('aria-disabled')).toBeUndefined();
        expect(currentItem.text()).toContain('owner · Current');
        expect(currentRow.attributes('aria-label')).toBe('Outboss');
        expect(
            wrapper.get('.workspace-switcher-row:first-child').classes(),
        ).toContain('current');
        expect(
            currentRow.get('[aria-label="Open settings for Outboss"]').exists(),
        ).toBe(true);
    });

    it('keeps long workspace names discoverable after visual truncation', () => {
        const wrapper = mountSwitcher();
        const longName = wrapper
            .findAll('.workspace-menu-item-copy strong')
            .find((item) => item.attributes('title') === longWorkspaceName);

        expect(longName?.text()).toBe(longWorkspaceName);
    });

    it('blocks duplicate workspace actions while a switch is pending', async () => {
        const wrapper = mountSwitcher();
        const switchLink = wrapper
            .findAllComponents(LinkStub)
            .find(
                (link) =>
                    link.props('href') === '/workspaces/11/switch' &&
                    link.props('data') === undefined,
            );

        expect(switchLink).toBeDefined();
        switchLink?.vm.$emit('start');
        await nextTick();

        expect(wrapper.get('.workspace-control').attributes('aria-busy')).toBe(
            'true',
        );
        expect(
            wrapper
                .get('.workspace-switcher-row.pending')
                .attributes('aria-busy'),
        ).toBe('true');
        expect(
            wrapper
                .findAllComponents(LinkStub)
                .filter((link) => link.props('method') === 'post')
                .every((link) => link.props('disabled') === true),
        ).toBe(true);

        switchLink?.vm.$emit('finish');
        await nextTick();

        expect(wrapper.get('.workspace-control').attributes('aria-busy')).toBe(
            'false',
        );
    });

    it('does not invent a workspace when page authentication is absent', () => {
        const wrapper = mount(WorkspaceSwitcher, {
            global: {
                stubs,
                config: { globalProperties: { $page: { props: {} } } },
            },
        });

        expect(wrapper.find('.workspace-control').exists()).toBe(false);
    });
});
