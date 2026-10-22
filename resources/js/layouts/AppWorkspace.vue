<script setup lang="ts">
import { Menu } from '@lucide/vue';
import {
    computed,
    getCurrentInstance,
    nextTick,
    onMounted,
    ref,
    watch,
} from 'vue';
import AccountMenu from '@/components/AccountMenu.vue';
import P0Sidebar from '@/components/P0Sidebar.vue';
import WorkspaceContext from '@/components/WorkspaceContext.vue';
import type { AppArea } from '@/types/navigation';

const props = defineProps<{
    area: AppArea;
    title: string;
    showSidebarTitle?: boolean;
}>();

const instance = getCurrentInstance();
const workspaceSettingsAreas = new Set<AppArea>([
    'mailboxes',
    'providers',
    'data',
    'suppressions',
    'api-keys',
    'devices',
    'gtm-profile',
    'managed-mail',
    'mail-experiments',
]);
const workspaceName = computed<string | null>(
    () =>
        instance?.appContext.config.globalProperties.$page?.props?.auth
            ?.workspace?.name ?? null,
);
const showWorkspaceBreadcrumb = computed(
    () => workspaceSettingsAreas.has(props.area) && workspaceName.value,
);

const collapsed = ref(false);
const mobileOpen = ref(false);
const mobileTrigger = ref<HTMLButtonElement | null>(null);
const globalSidebar = ref<{ focusFirst: () => void } | null>(null);

function sidebarStorage(): Storage | null {
    try {
        const storage = window.localStorage;

        return typeof storage?.getItem === 'function' &&
            typeof storage?.setItem === 'function'
            ? storage
            : null;
    } catch {
        return null;
    }
}

onMounted(() => {
    collapsed.value =
        sidebarStorage()?.getItem('outboss_global_sidebar') === 'collapsed';
});

watch(collapsed, (value) => {
    sidebarStorage()?.setItem(
        'outboss_global_sidebar',
        value ? 'collapsed' : 'expanded',
    );
});

async function openMobileNavigation(): Promise<void> {
    mobileOpen.value = true;
    await nextTick();
    globalSidebar.value?.focusFirst();
}

async function closeMobileNavigation(): Promise<void> {
    mobileTrigger.value?.focus();
    mobileOpen.value = false;
    await nextTick();
}
</script>

<template>
    <div class="app" :class="{ 'global-collapsed': collapsed }">
        <button
            ref="mobileTrigger"
            type="button"
            class="global-mobile-trigger"
            aria-label="Open navigation"
            aria-controls="global-sidebar"
            :aria-expanded="mobileOpen"
            @click="openMobileNavigation"
        >
            <Menu aria-hidden="true" />
        </button>
        <P0Sidebar
            ref="globalSidebar"
            v-model:collapsed="collapsed"
            v-model:mobile-open="mobileOpen"
            :area="area"
            @close-mobile="closeMobileNavigation"
        />
        <aside class="side" :class="`side-${area}`" :inert="mobileOpen">
            <p v-if="showSidebarTitle !== false" class="side-title">
                {{ title }}
            </p>
            <slot name="sidebar" />
            <WorkspaceContext :area="area" />
        </aside>
        <section class="workspace" :inert="mobileOpen">
            <div class="top">
                <div class="crumb">
                    <slot name="crumb">
                        <template v-if="showWorkspaceBreadcrumb">
                            <span class="workspace-crumb-parent">{{
                                workspaceName
                            }}</span>
                            <b class="workspace-crumb-separator">›</b>
                            <strong class="workspace-crumb-current">{{
                                title
                            }}</strong>
                        </template>
                        <span v-else class="top-title">{{ title }}</span>
                    </slot>
                </div>
                <div class="top-right">
                    <div v-if="$slots.actions" class="actions">
                        <slot name="actions" />
                    </div>
                    <AccountMenu />
                </div>
            </div>
            <slot />
        </section>
    </div>
</template>
