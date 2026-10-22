<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Inbox, Send, ShieldCheck } from '@lucide/vue';
import AppLogoWordmark from '@/components/AppLogoWordmark.vue';
import { home } from '@/routes';

defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <main class="auth-shell">
        <section class="auth-brand-panel" aria-label="About OutBoss">
            <Link :href="home()" class="auth-brand" aria-label="OutBoss home">
                <AppLogoWordmark :inverse="true" class="auth-wordmark" />
            </Link>
            <div class="auth-brand-copy">
                <p>Human outreach, handled carefully</p>
                <h2>Pick up every conversation where you left it.</h2>
                <ul>
                    <li>
                        <Inbox aria-hidden="true" /><span
                            ><strong>One focused inbox</strong
                            ><small
                                >Replies and campaign context stay
                                together.</small
                            ></span
                        >
                    </li>
                    <li>
                        <Send aria-hidden="true" /><span
                            ><strong>Thoughtful campaigns</strong
                            ><small
                                >Write the sequence once and send it
                                safely.</small
                            ></span
                        >
                    </li>
                    <li>
                        <ShieldCheck aria-hidden="true" /><span
                            ><strong>Your workspace, your data</strong
                            ><small
                                >Self-hosted with no AI provider
                                required.</small
                            ></span
                        >
                    </li>
                </ul>
            </div>
            <p class="auth-brand-foot">
                <Check aria-hidden="true" /> Safe sending controls built in
            </p>
        </section>

        <section class="auth-form-panel">
            <div class="auth-form-wrap">
                <Link
                    :href="home()"
                    class="auth-mobile-brand"
                    aria-label="OutBoss home"
                >
                    <AppLogoWordmark class="auth-wordmark" />
                </Link>
                <header class="auth-heading">
                    <p>OutBoss workspace</p>
                    <h1>{{ title }}</h1>
                    <span>{{ description }}</span>
                </header>
                <slot />
                <p class="auth-help">Private by default · Secure session</p>
            </div>
        </section>
    </main>
</template>

<style scoped>
.auth-shell {
    display: grid;
    min-height: 100svh;
    grid-template-columns: minmax(380px, 0.9fr) minmax(520px, 1.1fr);
    background: #f5f2ed;
    color: #211e19;
    font-family: 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif;
}
.auth-brand-panel {
    display: flex;
    min-height: 100svh;
    flex-direction: column;
    padding: clamp(32px, 5vw, 72px);
    background: #211e2e;
    color: #fff;
}
.auth-brand,
.auth-mobile-brand {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    color: inherit;
    text-decoration: none;
}
.auth-wordmark {
    display: block;
    width: 152px;
    height: auto;
}
.auth-brand-copy {
    max-width: 560px;
    margin: auto 0;
    padding-block: 60px;
}
.auth-brand-copy > p,
.auth-heading > p {
    margin: 0 0 16px;
    color: var(--ob-accent-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.13em;
    text-transform: uppercase;
}
.auth-brand-copy h2 {
    max-width: 520px;
    margin: 0;
    font-size: clamp(38px, 4vw, 64px);
    font-weight: 600;
    letter-spacing: -0.055em;
    line-height: 1.03;
}
.auth-brand-copy ul {
    display: grid;
    gap: 22px;
    max-width: 500px;
    margin: 48px 0 0;
    padding: 0;
    list-style: none;
}
.auth-brand-copy li {
    display: grid;
    grid-template-columns: 34px 1fr;
    align-items: start;
    gap: 14px;
}
.auth-brand-copy li > svg {
    width: 20px;
    margin-top: 2px;
    color: var(--ob-accent-border);
}
.auth-brand-copy li span {
    display: grid;
    gap: 4px;
}
.auth-brand-copy li strong {
    font-size: 14px;
}
.auth-brand-copy li small {
    color: #bdb8c9;
    font-size: 12px;
    line-height: 1.5;
}
.auth-brand-foot {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: #aaa4b8;
    font-size: 12px;
}
.auth-brand-foot svg {
    width: 15px;
    color: #55c69a;
}
.auth-form-panel {
    display: grid;
    min-height: 100svh;
    place-items: center;
    padding: 48px 28px;
}
.auth-form-wrap {
    width: min(100%, 420px);
}
.auth-mobile-brand {
    display: none;
    margin-bottom: 44px;
}
.auth-heading {
    margin-bottom: 34px;
}
.auth-heading > p {
    margin-bottom: 10px;
    color: var(--ob-accent);
}
.auth-heading h1 {
    margin: 0;
    font-size: 34px;
    font-weight: 650;
    letter-spacing: -0.04em;
}
.auth-heading > span {
    display: block;
    margin-top: 10px;
    color: #716a61;
    font-size: 14px;
    line-height: 1.55;
}
.auth-help {
    margin: 34px 0 0;
    padding-top: 20px;
    border-top: 1px solid #ddd7ce;
    color: #8a8379;
    font-size: 11px;
    text-align: center;
}
:deep(input) {
    min-height: 46px;
    border-color: #d8d1c7;
    background: #fff;
}
:deep(button[type='submit']) {
    min-height: 48px;
    border-radius: 10px;
    background: var(--ob-ink);
    font-weight: 650;
    box-shadow: 0 9px 22px rgb(75 73 69 / 16%);
}
:deep(a:focus-visible),
:deep(button:focus-visible),
:deep(input:focus-visible) {
    outline: 2px solid var(--ob-focus);
    outline-offset: 3px;
}
@media (max-width: 860px) {
    .auth-shell {
        display: block;
    }
    .auth-brand-panel {
        display: none;
    }
    .auth-form-panel {
        min-height: 100svh;
        padding: 32px 20px;
    }
    .auth-mobile-brand {
        display: inline-flex;
    }
    .auth-heading h1 {
        font-size: 30px;
    }
}
@media (prefers-reduced-motion: reduce) {
    :deep(*) {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
