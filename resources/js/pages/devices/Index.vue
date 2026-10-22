<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DeviceSessionController from '@/actions/App/Http/Controllers/Settings/DeviceSessionController';
import WorkspaceOpsSidebar from '@/components/WorkspaceOpsSidebar.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';

type Device = {
    id: string;
    name: string;
    platform: string;
    app_version: string | null;
    workspace: string | null;
    notifications: boolean;
    created_at: string | null;
    last_used_at: string | null;
};

defineProps<{
    devices: Device[];
}>();

const page = usePage();
const flash = computed(
    () =>
        (page.props.flash as
            { success?: string; error?: string } | undefined) ?? {},
);

const confirmingId = ref<string | null>(null);
const signingOutId = ref<string | null>(null);

function formatDate(value: string | null, fallback: string): string {
    if (!value) {
        return fallback;
    }

    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function signOut(device: Device): void {
    signingOutId.value = device.id;
    router.delete(DeviceSessionController.destroy.url(device.id), {
        preserveScroll: true,
        onFinish: () => {
            signingOutId.value = null;
            confirmingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Signed-in devices" />
    <AppWorkspace
        area="devices"
        title="Signed-in devices"
        :show-sidebar-title="false"
    >
        <template #sidebar>
            <WorkspaceOpsSidebar current="devices" />
        </template>
        <main class="devices-index">
            <header class="devices-page-heading">
                <div>
                    <h1>Signed-in devices</h1>
                    <p>
                        Phones signed in to your account with the Outboss iOS
                        app. Signing a device out stops its access and its
                        notifications straight away.
                    </p>
                </div>
                <div class="devices-totals">
                    <span
                        ><strong>{{ devices.length }}</strong>
                        {{ devices.length === 1 ? 'device' : 'devices' }}</span
                    >
                </div>
            </header>

            <p v-if="flash.success" class="hint">{{ flash.success }}</p>
            <p v-if="flash.error" class="bad">{{ flash.error }}</p>

            <section
                v-if="devices.length"
                class="devices-list"
                aria-label="Signed-in devices"
            >
                <div class="devices-list-head" aria-hidden="true">
                    <span>Device</span>
                    <span>Last active</span>
                    <span>Signed in</span>
                    <span />
                </div>
                <div
                    v-for="device in devices"
                    :key="device.id"
                    class="devices-row"
                    data-test="device-row"
                >
                    <span class="devices-row-primary">
                        <strong>{{ device.name }}</strong>
                        <small>
                            iOS<template v-if="device.app_version">
                                · app {{ device.app_version }}</template
                            ><template v-if="device.workspace">
                                · using {{ device.workspace }}</template
                            >
                        </small>
                        <span class="devices-scopes">
                            <span class="devices-scope">{{
                                device.notifications
                                    ? 'Notifications on'
                                    : 'Notifications off'
                            }}</span>
                        </span>
                    </span>
                    <span class="devices-row-metric">
                        <small>Last active</small>
                        <span>{{
                            formatDate(device.last_used_at, 'Not yet')
                        }}</span>
                    </span>
                    <span class="devices-row-metric">
                        <small>Signed in</small>
                        <span>{{ formatDate(device.created_at, '—') }}</span>
                    </span>
                    <span class="devices-row-action">
                        <template v-if="confirmingId === device.id">
                            <button
                                class="btn danger"
                                type="button"
                                :disabled="signingOutId === device.id"
                                data-test="confirm-sign-out-device"
                                @click="signOut(device)"
                            >
                                Sign out
                            </button>
                            <button
                                class="btn ghost"
                                type="button"
                                @click="confirmingId = null"
                            >
                                Cancel
                            </button>
                        </template>
                        <button
                            v-else
                            class="btn ghost"
                            type="button"
                            data-test="sign-out-device"
                            @click="confirmingId = device.id"
                        >
                            Sign out…
                        </button>
                    </span>
                </div>
            </section>

            <section v-else class="devices-empty">
                <p class="devices-eyebrow">iOS app</p>
                <h2>No devices signed in</h2>
                <p>
                    Install the Outboss app on your iPhone and sign in with this
                    account. It will appear here so you can sign it out later.
                </p>
            </section>
        </main>
    </AppWorkspace>
</template>

<style scoped>
.devices-index {
    min-width: 0;
    padding: 36px 40px 64px;
}

:global(.side-devices + .workspace) {
    overflow-x: hidden;
    overflow-y: auto;
}

.devices-page-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 32px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--ob-border);
}

.devices-eyebrow {
    margin: 0 0 7px;
    color: var(--ob-focus);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.devices-page-heading h1,
.devices-empty h2 {
    margin: 0;
    color: #1f1c18;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.03em;
}

.devices-empty h2 {
    font-size: 22px;
}

.devices-page-heading > div:first-child > p:last-child,
.devices-empty > p:not(.devices-eyebrow) {
    max-width: 620px;
    margin: 8px 0 0;
    color: #736c63;
    font-size: 13px;
    line-height: 1.55;
}

.devices-totals {
    display: flex;
    gap: 22px;
    color: #736c63;
    font-size: 12px;
    white-space: nowrap;
}

.devices-totals strong {
    color: #27231e;
    font-size: 15px;
}

.devices-list {
    margin-top: 8px;
}

.devices-list-head,
.devices-row {
    display: grid;
    grid-template-columns:
        minmax(220px, 1.6fr) minmax(130px, 0.55fr) minmax(130px, 0.55fr)
        minmax(170px, 0.5fr);
    align-items: center;
    gap: 16px;
}

.devices-list-head {
    min-height: 46px;
    border-bottom: 1px solid var(--ob-border);
    color: #8a8379;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.devices-row {
    min-height: 86px;
    border-bottom: 1px solid var(--ob-border-soft);
}

.devices-row-primary {
    display: grid;
    min-width: 0;
    gap: 6px;
}

.devices-row-primary strong {
    overflow: hidden;
    color: #27231e;
    font-size: 14px;
    font-weight: 640;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.devices-row-primary small {
    color: #8a8379;
    font-size: 12px;
}

.devices-scopes {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.devices-scope {
    display: inline-flex;
    width: fit-content;
    border-radius: 999px;
    padding: 3px 7px;
    background: #efede9;
    color: #665f56;
    font-size: 9px;
    font-weight: 650;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.devices-row-metric {
    display: grid;
    min-width: 0;
    gap: 4px;
    color: #736c63;
    font-size: 12px;
}

.devices-row-metric small {
    display: none;
}

.devices-row-action {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.devices-empty {
    display: grid;
    min-height: 170px;
    align-content: center;
    justify-items: start;
}

@media (max-width: 900px) {
    .devices-index {
        padding: 24px 18px 40px;
    }

    .devices-totals {
        display: none;
    }
}

@media (max-width: 760px) {
    .devices-page-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .devices-list-head {
        display: none;
    }

    .devices-row {
        grid-template-columns: 1fr;
        gap: 8px;
        padding: 16px 0;
    }

    .devices-row-metric small {
        display: inline;
    }

    .devices-row-action {
        justify-content: flex-start;
    }
}
</style>
