<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BarChart3,
    Building2,
    Database,
    Inbox,
    Megaphone,
    PanelLeftClose,
    PanelLeftOpen,
    Tag,
    Target,
    UsersRound,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppLogoWordmark from '@/components/AppLogoWordmark.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import type { AppArea } from '@/types/navigation';

type NavigationItem = {
    id: string;
    title: string;
    href?: string;
    icon: typeof Inbox;
    planned?: boolean;
};

const props = defineProps<{
    area: AppArea;
    collapsed: boolean;
    mobileOpen: boolean;
}>();

const emit = defineEmits<{
    'update:collapsed': [value: boolean];
    'update:mobileOpen': [value: boolean];
    closeMobile: [];
}>();

const root = ref<HTMLElement | null>(null);
const isMobile = ref(false);
let mobileQuery: MediaQueryList | null = null;

const groups: { label: string; items: NavigationItem[] }[] = [
    {
        label: 'Work',
        items: [
            {
                id: 'inbox',
                title: 'Inbox',
                href: '/unibox?folder=all_replies',
                icon: Inbox,
            },
            {
                id: 'campaigns',
                title: 'Outreach',
                href: '/campaigns',
                icon: Megaphone,
            },
        ],
    },
    {
        label: 'Build',
        items: [
            {
                id: 'objectives',
                title: 'Objectives',
                href: '/objectives',
                icon: Target,
            },
            {
                id: 'offers',
                title: 'Offers',
                href: '/offers',
                icon: Tag,
            },
        ],
    },
    {
        label: 'Inventory',
        items: [
            {
                id: 'accounts',
                title: 'Accounts',
                href: '/accounts',
                icon: Building2,
            },
            {
                id: 'people',
                title: 'People',
                href: '/prospects',
                icon: UsersRound,
            },
            {
                id: 'sources',
                title: 'Sources',
                href: '/prospect-sources',
                icon: Database,
            },
        ],
    },
    {
        label: 'Learn',
        items: [
            {
                id: 'reports',
                title: 'Reports',
                icon: BarChart3,
                planned: true,
            },
        ],
    },
];
function isActive(item: NavigationItem): boolean {
    if (item.id === 'inbox') {
        return props.area === 'unibox';
    }

    return item.id === props.area;
}

function closeAfterNavigation(): void {
    if (isMobile.value) {
        emit('closeMobile');
    }
}

function toggleFromSidebar(event: MouseEvent): void {
    const target = event.target as HTMLElement | null;

    if (
        isMobile.value ||
        event.button !== 0 ||
        !target?.closest ||
        target.closest(
            'a, button, input, select, textarea, [role="button"], [role="link"], [role="menuitem"]',
        )
    ) {
        return;
    }

    event.preventDefault();
    emit('update:collapsed', !props.collapsed);
}

function focusFirst(): void {
    root.value
        ?.querySelector<HTMLElement>('a:not([aria-disabled="true"]), button')
        ?.focus();
}

function handleEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.mobileOpen && !event.defaultPrevented) {
        emit('closeMobile');
    }
}

function trapMobileFocus(event: KeyboardEvent): void {
    if (event.key !== 'Tab' || !props.mobileOpen || !isMobile.value) {
        return;
    }

    const focusable = Array.from(
        root.value?.querySelectorAll<HTMLElement>(
            'a[href], button:not(:disabled), [tabindex]:not([tabindex="-1"])',
        ) ?? [],
    );

    if (!focusable.length) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function syncMobileState(event: MediaQueryListEvent | MediaQueryList): void {
    isMobile.value = event.matches;
}

onMounted(() => {
    if (typeof window.matchMedia === 'function') {
        mobileQuery = window.matchMedia('(max-width: 900px)');
        syncMobileState(mobileQuery);
        mobileQuery.addEventListener('change', syncMobileState);
    }

    document.addEventListener('keydown', handleEscape);
    document.addEventListener('keydown', trapMobileFocus);
});

onBeforeUnmount(() => {
    mobileQuery?.removeEventListener('change', syncMobileState);
    document.removeEventListener('keydown', handleEscape);
    document.removeEventListener('keydown', trapMobileFocus);
});

defineExpose({ focusFirst });
</script>

<template>
    <aside
        id="global-sidebar"
        ref="root"
        class="global-sidebar"
        :class="{ collapsed, 'mobile-open': mobileOpen }"
        :aria-hidden="isMobile && !mobileOpen ? 'true' : undefined"
        :inert="isMobile && !mobileOpen"
        @dblclick="toggleFromSidebar"
    >
        <div class="global-sidebar-brand">
            <Link
                href="/unibox?folder=all_open"
                class="global-brand-link"
                aria-label="OutBoss home"
                @click="closeAfterNavigation"
            >
                <AppLogoIcon class="global-brand-icon" />
                <AppLogoWordmark class="global-brand-wordmark" />
            </Link>
        </div>

        <nav class="global-navigation" aria-label="Primary navigation">
            <section
                v-for="group in groups"
                :key="group.label"
                class="global-nav-group"
                :aria-labelledby="`nav-${group.label.toLowerCase()}`"
            >
                <h2
                    :id="`nav-${group.label.toLowerCase()}`"
                    class="global-nav-label"
                >
                    {{ group.label }}
                </h2>
                <ul class="global-nav-list">
                    <li v-for="item in group.items" :key="item.id">
                        <Link
                            v-if="item.href"
                            :href="item.href"
                            class="global-nav-item"
                            :class="{ active: isActive(item) }"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            :aria-label="item.title"
                            @click="closeAfterNavigation"
                        >
                            <component :is="item.icon" aria-hidden="true" />
                            <span class="global-nav-copy">{{
                                item.title
                            }}</span>
                            <span class="global-nav-tooltip" aria-hidden="true">
                                {{ item.title }}
                            </span>
                        </Link>
                        <button
                            v-else
                            type="button"
                            class="global-nav-item planned"
                            disabled
                            :aria-label="`${item.title}, coming soon`"
                        >
                            <component :is="item.icon" aria-hidden="true" />
                            <span class="global-nav-copy">{{
                                item.title
                            }}</span>
                            <span class="global-planned-badge">Soon</span>
                            <span class="global-nav-tooltip" aria-hidden="true">
                                {{ item.title }} · coming soon
                            </span>
                        </button>
                    </li>
                </ul>
            </section>
        </nav>

        <button
            type="button"
            class="global-sidebar-collapse"
            :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            aria-controls="global-sidebar"
            :aria-expanded="!collapsed"
            @click="emit('update:collapsed', !collapsed)"
        >
            <PanelLeftOpen v-if="collapsed" aria-hidden="true" />
            <PanelLeftClose v-else aria-hidden="true" />
        </button>

        <div class="global-sidebar-footer workspace-switcher-footer">
            <WorkspaceSwitcher @navigated="closeAfterNavigation" />
        </div>
    </aside>

    <button
        v-if="mobileOpen"
        type="button"
        class="global-sidebar-scrim"
        aria-label="Close navigation"
        @click="emit('closeMobile')"
    />
</template>
