<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    current: 'profile' | 'security' | 'appearance';
}>();
</script>

<template>
    <nav class="account-side-nav" aria-label="Account settings">
        <section>
            <h2>Account</h2>
            <Link
                href="/account/profile"
                :class="{ active: current === 'profile' }"
                :aria-current="current === 'profile' ? 'page' : undefined"
                >Profile</Link
            >
            <Link
                href="/account/security"
                :class="{ active: current === 'security' }"
                :aria-current="current === 'security' ? 'page' : undefined"
                >Security</Link
            >
            <Link
                href="/account/appearance"
                :class="{ active: current === 'appearance' }"
                :aria-current="current === 'appearance' ? 'page' : undefined"
                >Appearance</Link
            >
        </section>
    </nav>
</template>

<style scoped>
.account-side-nav {
    padding: 22px 12px 18px;
}
.account-side-nav section {
    display: grid;
    gap: 2px;
}
.account-side-nav h2 {
    margin: 0;
    padding: 0 10px 7px;
    color: #918a80;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}
.account-side-nav a {
    display: flex;
    min-height: 40px;
    align-items: center;
    border-radius: 8px;
    padding: 0 10px;
    color: #4e4942;
    text-decoration: none;
}
.account-side-nav a:hover,
.account-side-nav a.active {
    background: var(--ob-accent-tint);
    color: var(--ob-accent);
}
.account-side-nav a:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}
@media (max-width: 900px) {
    .account-side-nav {
        padding: 0 4px 8px;
    }
    .account-side-nav h2 {
        display: none;
    }
    .account-side-nav section {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 4px;
    }
    .account-side-nav a {
        min-height: 44px;
        justify-content: center;
        padding: 6px 4px;
        font-size: 11px;
    }
}
</style>
