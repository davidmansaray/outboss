<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import GtmProfileKnowledgeList from '@/components/gtm-profile/GtmProfileKnowledgeList.vue';
import GtmProfileResearchState from '@/components/gtm-profile/GtmProfileResearchState.vue';
import { displayGtmValue } from '@/types/gtmProfile';
import type {
    GtmProfile,
    GtmProfileReviewItem,
    GtmProfileSection,
    GtmResearchState,
} from '@/types/gtmProfile';

const props = withDefaults(
    defineProps<{
        profile: GtmProfile;
        researchState: GtmResearchState;
        reviewItems?: GtmProfileReviewItem[];
        activeOffersCount?: number;
        isOwner?: boolean;
    }>(),
    {
        reviewItems: () => [],
        activeOffersCount: 0,
        isOwner: false,
    },
);

const emit = defineEmits<{
    edit: [section: GtmProfileSection];
    review: [];
    sources: [];
    addWebsite: [];
    retryResearch: [];
    refreshResearch: [];
}>();

const synthesis = computed(() => {
    const fromPayload = props.profile.payload.synthesis;
    const value = displayGtmValue(fromPayload);

    return (
        value ||
        'Outboss is still learning this company. Add a website or review the profile knowledge below.'
    );
});

const openReviewCount = computed(
    () => props.reviewItems.filter((item) => item.status === 'open').length,
);

const freshnessLabel = computed(() => {
    const value = props.researchState.last_researched_at;

    if (!value) {
        return 'No research completed yet';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Updated recently from';
    }

    const today = new Date();
    const isToday =
        today.getFullYear() === date.getFullYear() &&
        today.getMonth() === date.getMonth() &&
        today.getDate() === date.getDate();

    return isToday
        ? 'Updated today from'
        : `Updated ${new Intl.DateTimeFormat(undefined, {
              month: 'short',
              day: 'numeric',
          }).format(date)} from`;
});

const freshnessTone = computed(() => {
    if (props.researchState.state === 'failed') {
        return 'danger';
    }

    if (
        props.researchState.state === 'awaiting_provider' ||
        props.researchState.state === 'awaiting_budget' ||
        props.researchState.stale
    ) {
        return 'warning';
    }

    if (
        props.researchState.state === 'queued' ||
        props.researchState.state === 'running'
    ) {
        return 'working';
    }

    return props.researchState.state === 'ready' ? 'ready' : 'neutral';
});
</script>

<template>
    <main class="gtm-profile-overview" data-test="gtm-profile-overview">
        <div class="gtm-profile-column">
            <header class="gtm-minimal-header">
                <div>
                    <h1>GTM profile</h1>
                    <p>
                        Outboss uses this knowledge for research, qualification,
                        and outreach.
                    </p>
                </div>
                <button
                    v-if="isOwner"
                    type="button"
                    class="gtm-quiet-button"
                    @click="emit('edit', 'company')"
                >
                    Edit profile
                </button>
            </header>

            <GtmProfileResearchState
                v-if="researchState.state !== 'ready' || researchState.stale"
                :research-state="researchState"
                :is-owner="isOwner"
                @add-website="emit('addWebsite')"
                @retry="emit('retryResearch')"
                @refresh="emit('refreshResearch')"
            />

            <section
                class="gtm-understanding"
                aria-labelledby="gtm-synthesis-heading"
            >
                <div class="gtm-freshness">
                    <span
                        class="gtm-status-dot"
                        :class="`is-${freshnessTone}`"
                        aria-hidden="true"
                    />
                    <span id="gtm-synthesis">{{ freshnessLabel }}</span>
                    <button
                        type="button"
                        class="gtm-source-link"
                        @click="emit('sources')"
                    >
                        {{ researchState.source_count ?? 0 }} sources
                    </button>
                </div>
                <h2 id="gtm-synthesis-heading">{{ synthesis }}</h2>
            </section>

            <button
                v-if="openReviewCount > 0 && isOwner"
                type="button"
                class="gtm-attention-row"
                data-test="gtm-review-action"
                @click="emit('review')"
            >
                <span class="gtm-attention-icon" aria-hidden="true">
                    {{ openReviewCount }}
                </span>
                <span>
                    <strong
                        >{{ openReviewCount }} details could improve
                        results</strong
                    >
                    <small>Review only what research could not confirm.</small>
                </span>
                <span class="gtm-attention-action">
                    Review <ArrowUpRight aria-hidden="true" />
                </span>
            </button>

            <Link
                v-if="activeOffersCount > 0"
                href="/offers"
                class="gtm-offers-reference"
            >
                <span>
                    <strong>{{ activeOffersCount }} active offers</strong>
                    <small>Use this profile for targeting and messaging.</small>
                </span>
                <span>View offers <ArrowUpRight aria-hidden="true" /></span>
            </Link>

            <GtmProfileKnowledgeList
                :profile="profile"
                @select="emit('edit', $event)"
            />
        </div>
    </main>
</template>

<style scoped>
.gtm-profile-overview {
    min-height: 0;
    flex: 1;
    overflow: auto;
    background: #fbfaf7;
}

.gtm-profile-column {
    width: min(760px, calc(100% - 64px));
    margin: 0 auto;
    padding: 48px 0 96px;
}

.gtm-minimal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    padding-bottom: 31px;
    border-bottom: 1px solid var(--ob-border-strong);
}

.gtm-minimal-header h1 {
    margin: 0 0 7px;
    color: #29251f;
    font-size: 28px;
    font-weight: 650;
    letter-spacing: -0.035em;
}

.gtm-minimal-header p {
    margin: 0;
    color: #6b655c;
    font-size: 12px;
}

.gtm-quiet-button {
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 8px 10px;
    background: transparent;
    color: #6b655c;
    font-size: 11px;
    cursor: pointer;
}

.gtm-quiet-button:hover {
    border-color: var(--ob-border-strong);
    background: #fff;
    color: #29251f;
}

.gtm-understanding {
    padding: 36px 0 32px;
}

.gtm-freshness {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #6b655c;
    font-size: 10px;
}

.gtm-status-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #18794e;
}

.gtm-status-dot.is-neutral {
    background: #aaa39a;
}

.gtm-status-dot.is-working {
    background: var(--ob-accent-strong);
}

.gtm-status-dot.is-warning {
    background: #c18516;
}

.gtm-status-dot.is-danger {
    background: #b42318;
}

.gtm-source-link {
    border: 0;
    padding: 0;
    background: transparent;
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 3px;
    cursor: pointer;
}

.gtm-understanding h2 {
    max-width: 700px;
    margin: 15px 0 0;
    color: #29251f;
    font-size: 23px;
    font-weight: 520;
    line-height: 1.42;
    letter-spacing: -0.025em;
}

.gtm-attention-row {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr) auto;
    align-items: center;
    width: 100%;
    gap: 14px;
    border: 0;
    border-top: 1px solid #e8d5a8;
    border-bottom: 1px solid #e8d5a8;
    padding: 15px 0;
    background: transparent;
    text-align: left;
    cursor: pointer;
}

.gtm-attention-icon {
    display: grid;
    width: 30px;
    height: 30px;
    place-items: center;
    border-radius: 50%;
    background: #fff3da;
    color: #9a6500;
    font-size: 11px;
    font-weight: 700;
}

.gtm-attention-row strong,
.gtm-attention-row small {
    display: block;
}

.gtm-attention-row strong {
    color: #29251f;
    font-size: 12px;
}

.gtm-attention-row small {
    margin-top: 3px;
    color: #6b655c;
    font-size: 10px;
}

.gtm-attention-action,
.gtm-offers-reference > span:last-child {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #6b655c;
    font-size: 10px;
    font-weight: 650;
}

.gtm-attention-action :deep(svg),
.gtm-offers-reference :deep(svg) {
    width: 14px;
    height: 14px;
}

.gtm-attention-row:hover .gtm-attention-action,
.gtm-offers-reference:hover > span:last-child {
    color: var(--ob-accent);
}

.gtm-offers-reference {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-top: 20px;
    padding: 13px 0;
    color: inherit;
    text-decoration: none;
}

.gtm-offers-reference strong,
.gtm-offers-reference small {
    display: block;
}

.gtm-offers-reference strong {
    color: #29251f;
    font-size: 11px;
}

.gtm-offers-reference small {
    margin-top: 4px;
    color: #6b655c;
    font-size: 10px;
}

@media (max-width: 900px) {
    .gtm-profile-column {
        width: calc(100% - 32px);
        padding-top: 31px;
    }

    .gtm-understanding h2 {
        font-size: 20px;
    }
}

@media (max-width: 620px) {
    .gtm-profile-column {
        width: calc(100% - 24px);
        padding-bottom: 68px;
    }

    .gtm-minimal-header {
        padding-bottom: 24px;
    }

    .gtm-minimal-header .gtm-quiet-button {
        display: none;
    }

    .gtm-understanding {
        padding: 29px 0;
    }

    .gtm-understanding h2 {
        font-size: 19px;
    }

    .gtm-attention-row {
        grid-template-columns: 30px minmax(0, 1fr);
    }

    .gtm-attention-action {
        display: none;
    }
}
</style>
