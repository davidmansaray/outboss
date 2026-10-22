<script setup lang="ts">
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import { displayGtmValue, GTM_PROFILE_SECTIONS } from '@/types/gtmProfile';
import type { GtmProfile, GtmProfileSection } from '@/types/gtmProfile';

const props = defineProps<{
    profile: GtmProfile;
}>();

const emit = defineEmits<{
    select: [section: GtmProfileSection];
}>();

const rows = computed(() =>
    GTM_PROFILE_SECTIONS.map((section) => {
        const data = props.profile.payload[section.id];
        const values =
            data && typeof data === 'object'
                ? Object.values(data)
                      .map((value) => displayGtmValue(value))
                      .filter(Boolean)
                : [];

        return {
            ...section,
            summary: values.slice(0, 2).join(' · ') || section.description,
        };
    }),
);
</script>

<template>
    <section class="gtm-knowledge-list" aria-labelledby="gtm-knowledge-heading">
        <div class="gtm-list-heading">
            <h2 id="gtm-knowledge-heading">Profile knowledge</h2>
            <span>Used automatically by Outboss</span>
        </div>
        <div class="gtm-knowledge-rows">
            <button
                v-for="row in rows"
                :key="row.id"
                type="button"
                class="gtm-knowledge-row"
                :data-section="row.id"
                @click="emit('select', row.id)"
            >
                <span class="gtm-knowledge-copy">
                    <strong>{{ row.label }}</strong>
                    <small>{{ row.summary }}</small>
                </span>
                <span class="gtm-row-action">
                    Edit
                    <ArrowUpRight class="gtm-row-arrow" aria-hidden="true" />
                </span>
            </button>
        </div>
    </section>
</template>

<style scoped>
.gtm-knowledge-list {
    margin-top: 28px;
}

.gtm-list-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 10px;
}

.gtm-list-heading h2 {
    margin: 0;
    color: #29251f;
    font-size: 13px;
    font-weight: 650;
}

.gtm-list-heading span {
    color: #8a847a;
    font-size: 9px;
}

.gtm-knowledge-rows {
    border-top: 1px solid var(--ob-border-strong);
}

.gtm-knowledge-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    width: 100%;
    min-height: 70px;
    gap: 11px;
    border: 0;
    border-bottom: 1px solid var(--ob-border-strong);
    padding: 13px 0;
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
}

.gtm-knowledge-row:hover .gtm-row-action,
.gtm-knowledge-row:focus-visible .gtm-row-action {
    color: var(--ob-accent);
}

.gtm-knowledge-copy {
    min-width: 0;
}

.gtm-knowledge-copy strong,
.gtm-knowledge-copy small {
    display: block;
}

.gtm-knowledge-copy strong {
    color: #29251f;
    font-size: 11px;
    font-weight: 650;
}

.gtm-knowledge-copy small {
    overflow: hidden;
    margin-top: 5px;
    color: #6b655c;
    font-size: 11px;
    line-height: 1.4;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.gtm-row-action {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #6b655c;
    font-size: 10px;
    font-weight: 650;
    white-space: nowrap;
}

.gtm-row-arrow {
    width: 14px;
    height: 14px;
    color: currentColor;
    transition:
        color 140ms ease,
        transform 140ms ease;
}

.gtm-knowledge-row:hover .gtm-row-arrow,
.gtm-knowledge-row:focus-visible .gtm-row-arrow {
    transform: translate(2px, -2px);
}

@media (max-width: 620px) {
    .gtm-list-heading span {
        display: none;
    }

    .gtm-knowledge-row {
        grid-template-columns: minmax(0, 1fr) auto;
    }
}

@media (prefers-reduced-motion: reduce) {
    .gtm-row-arrow {
        transition: none;
    }
}
</style>
