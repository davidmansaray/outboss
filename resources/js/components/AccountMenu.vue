<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, LogOut, UserRound } from '@lucide/vue';
import { computed, getCurrentInstance } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const instance = getCurrentInstance();
const user = computed(() => {
    const current =
        instance?.appContext.config.globalProperties.$page?.props?.auth?.user;

    return current ?? { name: 'Account', email: 'Account settings' };
});
const userInitials = computed(() =>
    user.value.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join(''),
);
const firstName = computed(() => user.value.name.split(/\s+/)[0] || 'Account');
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger :as-child="true">
            <button
                type="button"
                class="account-menu-trigger"
                :aria-label="`Open account menu for ${user.name}`"
            >
                <span class="account-menu-avatar">{{ userInitials }}</span>
                <span class="account-menu-name">{{ firstName }}</span>
                <ChevronDown aria-hidden="true" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            side="bottom"
            align="end"
            :side-offset="7"
            class="account-menu-content w-72 rounded-xl p-1.5"
        >
            <DropdownMenuLabel class="px-2 py-2 font-normal">
                <div class="account-menu-heading">
                    <span class="account-menu-avatar">{{ userInitials }}</span>
                    <span>
                        <strong>{{ user.name }}</strong>
                        <small>{{ user.email }}</small>
                    </span>
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem :as-child="true">
                    <Link href="/account/profile" class="w-full">
                        <UserRound />
                        <span class="workspace-menu-item-copy">
                            Account settings
                            <small>Profile, security and appearance</small>
                        </span>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem :as-child="true">
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="w-full"
                    >
                        <LogOut />
                        Log out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
