<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    current:
        | 'mailboxes'
        | 'data'
        | 'suppressions'
        | 'api-keys'
        | 'devices'
        | 'providers'
        | 'gtm-profile'
        | 'managed-mail'
        | 'mail-experiments';
    showInternal?: boolean;
}>();
</script>

<template>
    <nav class="ops-side-nav" aria-label="Workspace settings">
        <section>
            <h2>Strategy</h2>
            <Link
                href="/settings/gtm-profile"
                :class="{ active: props.current === 'gtm-profile' }"
                :aria-current="
                    props.current === 'gtm-profile' ? 'page' : undefined
                "
                >GTM profile</Link
            >
        </section>
        <section>
            <h2>Connected services</h2>
            <Link
                href="/settings/mailboxes"
                :class="{ active: current === 'mailboxes' }"
                :aria-current="current === 'mailboxes' ? 'page' : undefined"
                >Mailboxes</Link
            >
            <Link
                href="/settings/ai-research"
                :class="{ active: current === 'providers' }"
                :aria-current="current === 'providers' ? 'page' : undefined"
                >AI & research</Link
            >
        </section>
        <section>
            <h2>Data controls</h2>
            <Link
                href="/settings/exports"
                :class="{ active: current === 'data' }"
                :aria-current="current === 'data' ? 'page' : undefined"
                >Exports</Link
            >
            <Link
                href="/settings/suppressions"
                :class="{ active: current === 'suppressions' }"
                :aria-current="current === 'suppressions' ? 'page' : undefined"
                >Suppressions</Link
            >
        </section>
        <section>
            <h2>Account</h2>
            <Link
                href="/settings/devices"
                :class="{ active: current === 'devices' }"
                :aria-current="current === 'devices' ? 'page' : undefined"
                >Signed-in devices</Link
            >
        </section>
        <section>
            <h2>Developer</h2>
            <Link
                href="/settings/api-keys"
                :class="{ active: current === 'api-keys' }"
                :aria-current="current === 'api-keys' ? 'page' : undefined"
                >API keys</Link
            >
        </section>
        <section v-if="props.showInternal" data-test="internal-settings-nav">
            <h2>Internal</h2>
            <Link
                href="/settings/managed-mail"
                :class="{ active: props.current === 'managed-mail' }"
                :aria-current="
                    props.current === 'managed-mail' ? 'page' : undefined
                "
                >Managed mail</Link
            >
            <Link
                href="/settings/mail-experiments"
                :class="{ active: props.current === 'mail-experiments' }"
                :aria-current="
                    props.current === 'mail-experiments' ? 'page' : undefined
                "
                >Mail experiments</Link
            >
        </section>
    </nav>
</template>

<style scoped>
.ops-side-nav {
    display: grid;
    gap: 24px;
    padding: 22px 12px 18px;
}

.ops-side-nav section {
    display: grid;
    gap: 2px;
}

.ops-side-nav h2 {
    margin: 0;
    padding: 0 10px 7px;
    color: #918a80;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.ops-side-nav a {
    display: flex;
    min-height: 40px;
    align-items: center;
    border-radius: 8px;
    padding: 0 10px;
    color: #4e4942;
    text-decoration: none;
}

.ops-side-nav a:hover,
.ops-side-nav a.active {
    background: var(--ob-accent-tint);
    color: var(--ob-accent);
}

.ops-side-nav a:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}

@media (max-width: 900px) {
    .ops-side-nav {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        padding: 0 4px 8px;
    }

    .ops-side-nav section {
        min-width: 0;
    }

    .ops-side-nav h2 {
        padding: 0 4px 4px;
        text-align: center;
    }

    .ops-side-nav a {
        min-height: 44px;
        justify-content: center;
        padding: 6px 4px;
        font-size: 11px;
        text-align: center;
    }
}
</style>
