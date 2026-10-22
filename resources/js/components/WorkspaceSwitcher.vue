<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronsUpDown, LoaderCircle, Plus, Settings } from '@lucide/vue';
import { computed, getCurrentInstance, nextTick, ref } from 'vue';
import CreateWorkspaceDialog from '@/components/CreateWorkspaceDialog.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import WorkspaceAvatar from '@/components/WorkspaceAvatar.vue';
import type { Auth } from '@/types/auth';

const emit = defineEmits<{ navigated: [] }>();

const instance = getCurrentInstance();
const auth = computed<Auth | null>(
    () =>
        instance?.appContext.config.globalProperties.$page?.props?.auth ?? null,
);
const workspace = computed(() => auth.value?.workspace ?? null);
const workspaces = computed(() => {
    const available = auth.value?.workspaces.length
        ? auth.value.workspaces
        : workspace.value
          ? [workspace.value]
          : [];
    const current = workspace.value;

    if (current === null) {
        return available;
    }

    return [
        available.find((item) => item.id === current.id) ?? current,
        ...available.filter((item) => item.id !== current.id),
    ];
});
const roleLabel = computed(() => {
    const role = workspace.value?.role;

    return role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Member';
});

const createOpen = ref(false);
const pendingWorkspaceId = ref<number | null>(null);

function startSwitch(workspaceId: number): void {
    pendingWorkspaceId.value = workspaceId;
}

function finishSwitch(): void {
    pendingWorkspaceId.value = null;
}

async function openCreateWorkspace(): Promise<void> {
    emit('navigated');
    await nextTick();
    createOpen.value = true;
}
</script>

<template>
    <DropdownMenu v-if="workspace">
        <DropdownMenuTrigger :as-child="true">
            <button
                type="button"
                class="workspace-control"
                :aria-busy="pendingWorkspaceId !== null"
                :aria-label="`Switch workspace, current workspace ${workspace.name}`"
                :title="workspace.name"
            >
                <WorkspaceAvatar
                    :name="workspace.name"
                    :src="workspace.avatar_url"
                />
                <span class="global-footer-copy">
                    <strong>{{ workspace.name }}</strong>
                    <small>{{ roleLabel }} · Switch workspace</small>
                </span>
                <LoaderCircle
                    v-if="pendingWorkspaceId !== null"
                    class="workspace-switching-spinner"
                    aria-hidden="true"
                />
                <ChevronsUpDown
                    v-else
                    class="workspace-chevron"
                    aria-hidden="true"
                />
                <span class="global-nav-tooltip" aria-hidden="true">
                    {{ workspace.name }}
                </span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            side="bottom"
            align="start"
            :side-offset="7"
            class="workspace-switcher-menu w-72 rounded-xl p-1.5"
        >
            <div class="workspace-menu-header">
                <DropdownMenuLabel class="px-2 py-1.5 text-xs font-medium">
                    Workspaces
                </DropdownMenuLabel>
                <DropdownMenuItem
                    class="workspace-create-icon"
                    aria-label="Create workspace"
                    @select="openCreateWorkspace"
                >
                    <Plus aria-hidden="true" />
                </DropdownMenuItem>
            </div>
            <DropdownMenuGroup class="workspace-switcher-list">
                <div
                    v-for="available in workspaces"
                    :key="available.id"
                    class="workspace-switcher-row"
                    :class="{
                        current: available.id === workspace.id,
                        pending: pendingWorkspaceId === available.id,
                    }"
                    role="group"
                    :aria-label="available.name"
                    :aria-busy="pendingWorkspaceId === available.id"
                >
                    <DropdownMenuItem
                        v-if="available.id === workspace.id"
                        class="workspace-switcher-item"
                        aria-current="true"
                        :aria-label="`${available.name}, ${available.role ?? 'member'}, current workspace`"
                        @select.prevent
                    >
                        <WorkspaceAvatar
                            :name="available.name"
                            :src="available.avatar_url"
                        />
                        <span class="workspace-menu-item-copy">
                            <strong :title="available.name">{{
                                available.name
                            }}</strong>
                            <small class="capitalize">
                                {{ available.role ?? 'member' }} · Current
                            </small>
                        </span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-else
                        :as-child="true"
                        class="workspace-switcher-item"
                    >
                        <Link
                            :href="`/workspaces/${available.id}/switch`"
                            method="post"
                            as="button"
                            class="w-full"
                            :disabled="pendingWorkspaceId !== null"
                            @start="startSwitch(available.id)"
                            @finish="finishSwitch"
                            @cancel="finishSwitch"
                            @click="emit('navigated')"
                        >
                            <WorkspaceAvatar
                                :name="available.name"
                                :src="available.avatar_url"
                            />
                            <span class="workspace-menu-item-copy">
                                <strong :title="available.name">{{
                                    available.name
                                }}</strong>
                                <small class="capitalize">{{
                                    available.role ?? 'member'
                                }}</small>
                            </span>
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :as-child="true"
                        class="workspace-settings-item"
                    >
                        <Link
                            v-if="available.id === workspace.id"
                            href="/settings/mailboxes"
                            :aria-label="`Open settings for ${available.name}`"
                            @click="emit('navigated')"
                        >
                            <Settings aria-hidden="true" />
                        </Link>
                        <Link
                            v-else
                            :href="`/workspaces/${available.id}/switch`"
                            method="post"
                            as="button"
                            :data="{ destination: 'settings' }"
                            :disabled="pendingWorkspaceId !== null"
                            :aria-label="`Open settings for ${available.name}`"
                            @start="startSwitch(available.id)"
                            @finish="finishSwitch"
                            @cancel="finishSwitch"
                            @click="emit('navigated')"
                        >
                            <Settings aria-hidden="true" />
                        </Link>
                    </DropdownMenuItem>
                </div>
            </DropdownMenuGroup>
        </DropdownMenuContent>
    </DropdownMenu>

    <CreateWorkspaceDialog
        v-if="createOpen"
        v-model:open="createOpen"
        @navigated="emit('navigated')"
    />
</template>
