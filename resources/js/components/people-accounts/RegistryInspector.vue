<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import EntityIdentity from '@/components/people-accounts/EntityIdentity.vue';
import RegistryStatusPill from '@/components/people-accounts/RegistryStatusPill.vue';
import {
    accountSourceLabel,
    accountWebsiteHref,
    initials,
    updatedLabel,
} from '@/lib/peopleAccountsFormatters';
import type { RegistrySelection } from '@/types';

type EntitySelection = Exclude<RegistrySelection, { kind: 'source' }>;

const props = defineProps<{
    selection: EntitySelection;
}>();
const emit = defineEmits<{
    close: [];
    selectPerson: [id: number];
    selectAccount: [id: number];
    addPerson: [];
    edit: [];
}>();

const panel = ref<HTMLElement | null>(null);
const title = ref<HTMLElement | null>(null);
const renderedSelection = ref<EntitySelection>(props.selection);
const isModal = ref(false);
const confirmingDelete = ref(false);
const deleting = ref(false);
let media: MediaQueryList | null = null;
let clearTimer: ReturnType<typeof setTimeout> | null = null;

const open = computed(() => props.selection !== null);
const websiteHref = computed(() =>
    renderedSelection.value?.kind === 'account'
        ? accountWebsiteHref(renderedSelection.value.record.website)
        : null,
);

function syncModal(query: MediaQueryList | MediaQueryListEvent): void {
    isModal.value = query.matches;
}

function focusableElements(): HTMLElement[] {
    return Array.from(
        panel.value?.querySelectorAll<HTMLElement>(
            'button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ) ?? [],
    );
}

function handleKeydown(event: KeyboardEvent): void {
    if (!open.value) {
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');

        return;
    }

    if (event.key !== 'Tab' || !isModal.value) {
        return;
    }

    const focusable = focusableElements();

    if (!focusable.length) {
        event.preventDefault();
        title.value?.focus({ preventScroll: true });

        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus({ preventScroll: true });
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus({ preventScroll: true });
    }
}

watch(
    () => props.selection,
    async (selection) => {
        if (clearTimer) {
            clearTimeout(clearTimer);
            clearTimer = null;
        }

        if (selection) {
            renderedSelection.value = selection;
            confirmingDelete.value = false;
            await nextTick();
            title.value?.focus({ preventScroll: true });
        } else {
            clearTimer = setTimeout(() => {
                renderedSelection.value = null;
            }, 260);
        }
    },
);

onMounted(() => {
    media = window.matchMedia('(max-width: 980px)');
    syncModal(media);
    media.addEventListener('change', syncModal);
    document.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    if (clearTimer) {
        clearTimeout(clearTimer);
    }

    media?.removeEventListener('change', syncModal);
    document.removeEventListener('keydown', handleKeydown);
});

function deleteAccount(): void {
    if (renderedSelection.value?.kind !== 'account' || deleting.value) {
        return;
    }

    deleting.value = true;
    router.delete(`/accounts/${renderedSelection.value.record.id}`, {
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
            emit('close');
        },
    });
}

function deletePerson(): void {
    if (renderedSelection.value?.kind !== 'person' || deleting.value) {
        return;
    }

    deleting.value = true;
    router.delete(`/people/${renderedSelection.value.record.id}`, {
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
            emit('close');
        },
    });
}
</script>

<template>
    <button
        class="registry-inspector-scrim"
        :class="{ open }"
        type="button"
        aria-label="Close details panel"
        :tabindex="open && isModal ? 0 : -1"
        @click="emit('close')"
    />
    <aside
        ref="panel"
        class="registry-inspector"
        :class="{ open }"
        :aria-hidden="!open"
        :aria-modal="open && isModal ? 'true' : undefined"
        :role="open ? (isModal ? 'dialog' : 'complementary') : undefined"
        :inert="!open"
        aria-labelledby="registry-inspector-title"
    >
        <template v-if="renderedSelection">
            <header class="registry-inspector-head">
                <span
                    class="registry-inspector-avatar"
                    :class="{ person: renderedSelection.kind === 'person' }"
                    aria-hidden="true"
                >
                    {{ initials(renderedSelection.record.name) }}
                </span>
                <span class="registry-inspector-heading-copy">
                    <small>{{
                        renderedSelection.kind === 'person'
                            ? 'Person'
                            : 'Account'
                    }}</small>
                    <h2 id="registry-inspector-title" ref="title" tabindex="-1">
                        {{ renderedSelection.record.name }}
                    </h2>
                </span>
                <button
                    type="button"
                    class="registry-inspector-close"
                    :aria-label="`Close ${renderedSelection.record.name} details`"
                    @click="emit('close')"
                >
                    <X aria-hidden="true" />
                </button>
            </header>

            <div
                v-if="renderedSelection.kind === 'person'"
                class="registry-inspector-body"
            >
                <dl class="registry-facts">
                    <div>
                        <dt>Email</dt>
                        <dd>{{ renderedSelection.record.email }}</dd>
                    </div>
                    <div>
                        <dt>Reply</dt>
                        <dd>
                            <RegistryStatusPill
                                :label="renderedSelection.record.reply"
                            />
                        </dd>
                    </div>
                    <div>
                        <dt>Eligibility</dt>
                        <dd>
                            <RegistryStatusPill
                                :label="renderedSelection.record.eligibility"
                            />
                        </dd>
                    </div>
                    <div>
                        <dt>Job title</dt>
                        <dd>
                            {{ renderedSelection.record.role || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt>Account</dt>
                        <dd>
                            <button
                                v-if="
                                    renderedSelection.record.account &&
                                    renderedSelection.record.account_id
                                "
                                type="button"
                                class="registry-account-link"
                                @click="
                                    emit(
                                        'selectAccount',
                                        renderedSelection.record.account_id,
                                    )
                                "
                            >
                                {{ renderedSelection.record.account }}
                            </button>
                            <template v-else>No account</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Source</dt>
                        <dd>{{ renderedSelection.record.source }}</dd>
                    </div>
                    <div>
                        <dt>Updated</dt>
                        <dd>
                            {{
                                updatedLabel(
                                    renderedSelection.record.updated_at,
                                )
                            }}
                        </dd>
                    </div>
                </dl>

                <section
                    v-if="renderedSelection.record.notes"
                    class="registry-inspector-section"
                >
                    <h3>Notes</h3>
                    <p class="registry-notes">
                        {{ renderedSelection.record.notes }}
                    </p>
                </section>

                <section
                    v-if="renderedSelection.record.tags.length"
                    class="registry-inspector-section"
                >
                    <h3>Tags</h3>
                    <p class="registry-tag-list">
                        <span
                            v-for="tag in renderedSelection.record.tags"
                            :key="tag"
                            >{{ tag }}</span
                        >
                    </p>
                </section>

                <section class="registry-inspector-section">
                    <h3>Campaign history</h3>
                    <p
                        v-if="!renderedSelection.record.campaigns.length"
                        class="registry-empty-people"
                    >
                        Not enrolled in a campaign yet
                    </p>
                    <div
                        v-for="campaign in renderedSelection.record.campaigns"
                        :key="campaign.id"
                        class="registry-activity"
                    >
                        <i aria-hidden="true" />
                        <span>
                            <strong>{{ campaign.name }}</strong>
                            <small>{{
                                campaign.status.replaceAll('_', ' ')
                            }}</small>
                        </span>
                        <time>{{ updatedLabel(campaign.updated_at) }}</time>
                    </div>
                </section>
            </div>

            <div v-else class="registry-inspector-body">
                <dl class="registry-facts">
                    <div>
                        <dt>Website</dt>
                        <dd>
                            <a
                                v-if="websiteHref"
                                :href="websiteHref"
                                target="_blank"
                                rel="noreferrer"
                            >
                                {{
                                    renderedSelection.record.website ||
                                    renderedSelection.record.domain
                                }}
                            </a>
                            <template v-else>
                                {{
                                    renderedSelection.record.domain ||
                                    'No website'
                                }}
                            </template>
                        </dd>
                    </div>
                    <div>
                        <dt>Domain</dt>
                        <dd>
                            {{ renderedSelection.record.domain || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt>Readiness</dt>
                        <dd>
                            <RegistryStatusPill
                                :label="renderedSelection.record.readiness"
                            />
                        </dd>
                    </div>
                    <div>
                        <dt>People ready</dt>
                        <dd>
                            <template
                                v-if="renderedSelection.record.people_count"
                            >
                                {{ renderedSelection.record.ready_count }} of
                                {{ renderedSelection.record.people_count }}
                            </template>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <div>
                        <dt>Campaigns</dt>
                        <dd>{{ renderedSelection.record.campaigns_count }}</dd>
                    </div>
                    <div>
                        <dt>Supplied by</dt>
                        <dd>
                            {{
                                renderedSelection.record.provenance
                                    ?.primary_source?.name ||
                                accountSourceLabel(
                                    renderedSelection.record.source,
                                )
                            }}
                        </dd>
                    </div>
                </dl>

                <section
                    v-if="renderedSelection.record.notes"
                    class="registry-inspector-section"
                >
                    <h3>Notes</h3>
                    <p class="registry-notes">
                        {{ renderedSelection.record.notes }}
                    </p>
                </section>

                <section
                    v-if="renderedSelection.record.tags.length"
                    class="registry-inspector-section"
                >
                    <h3>Tags</h3>
                    <p class="registry-tag-list">
                        <span
                            v-for="tag in renderedSelection.record.tags"
                            :key="tag"
                            >{{ tag }}</span
                        >
                    </p>
                </section>

                <section class="registry-inspector-section">
                    <div class="registry-section-heading">
                        <h3>People</h3>
                        <span>{{ renderedSelection.record.people_count }}</span>
                    </div>
                    <p
                        v-if="!renderedSelection.record.people_count"
                        class="registry-empty-people"
                    >
                        No people at this account yet
                    </p>
                    <button
                        v-for="person in renderedSelection.record.people"
                        :key="person.id"
                        type="button"
                        class="registry-person-link"
                        @click="emit('selectPerson', person.id)"
                    >
                        <EntityIdentity
                            :name="person.name"
                            :secondary="person.role || person.email"
                            person
                        />
                        <RegistryStatusPill :label="person.reply" />
                        <span aria-hidden="true">›</span>
                    </button>
                </section>
            </div>

            <footer
                v-if="renderedSelection.kind === 'account'"
                class="registry-inspector-foot"
            >
                <template v-if="confirmingDelete">
                    <p>
                        People keep their campaign history and stay in the
                        registry.
                    </p>
                    <button
                        type="button"
                        data-test="cancel-delete-account"
                        :disabled="deleting"
                        @click="confirmingDelete = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="danger"
                        data-test="confirm-delete-account"
                        :disabled="deleting"
                        @click="deleteAccount"
                    >
                        {{ deleting ? 'Deleting…' : 'Delete account' }}
                    </button>
                </template>
                <template v-else>
                    <a
                        class="intelligence-account-link"
                        data-test="account-intelligence-link"
                        :href="`/accounts/${renderedSelection.record.id}/intelligence`"
                    >
                        Account intelligence
                    </a>
                    <button
                        type="button"
                        data-test="edit-account"
                        @click="emit('edit')"
                    >
                        Edit
                    </button>
                    <button
                        type="button"
                        class="danger-quiet"
                        data-test="delete-account"
                        @click="confirmingDelete = true"
                    >
                        Delete
                    </button>
                    <button
                        type="button"
                        data-test="add-account-person"
                        @click="emit('addPerson')"
                    >
                        Add person
                    </button>
                </template>
            </footer>
            <footer
                v-else-if="renderedSelection.kind === 'person'"
                class="registry-inspector-foot"
            >
                <template v-if="confirmingDelete">
                    <p>Campaign history is preserved.</p>
                    <button
                        type="button"
                        data-test="cancel-delete-person"
                        :disabled="deleting"
                        @click="confirmingDelete = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="danger"
                        data-test="confirm-delete-person"
                        :disabled="deleting"
                        @click="deletePerson"
                    >
                        {{ deleting ? 'Deleting…' : 'Delete person' }}
                    </button>
                </template>
                <template v-else>
                    <button
                        type="button"
                        data-test="edit-person"
                        @click="emit('edit')"
                    >
                        Edit
                    </button>
                    <button
                        type="button"
                        class="danger-quiet"
                        data-test="delete-person"
                        @click="confirmingDelete = true"
                    >
                        Delete
                    </button>
                </template>
            </footer>
        </template>
    </aside>
</template>

<style scoped>
.registry-inspector {
    position: fixed;
    top: 52px;
    right: 0;
    bottom: 0;
    z-index: 45;
    display: flex;
    width: clamp(360px, 34vw, 440px);
    max-width: calc(100vw - 56px);
    flex-direction: column;
    border-left: 1px solid var(--ob-border);
    background: #fff;
    box-shadow: -10px 0 28px rgb(38 32 25 / 8%);
    transform: translate3d(102%, 0, 0);
    visibility: hidden;
    contain: layout paint;
    transition:
        transform 240ms cubic-bezier(0.22, 1, 0.36, 1),
        visibility 0s linear 240ms;
    will-change: transform;
}

.registry-inspector.open {
    transform: translate3d(0, 0, 0);
    visibility: visible;
    transition-delay: 0s;
}

.registry-inspector-head {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    padding: 17px;
    border-bottom: 1px solid var(--ob-border);
}

.registry-inspector-avatar {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 9px;
    background: var(--ob-border-soft);
    color: #625d54;
    font-size: 10px;
    font-weight: 700;
}

.registry-inspector-avatar.person {
    border-radius: 50%;
    background: var(--ob-accent-tint);
    color: var(--ob-accent);
}

.registry-inspector-heading-copy {
    min-width: 0;
}

.registry-inspector-heading-copy small {
    display: block;
    margin-bottom: 2px;
    color: #6f685f;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.registry-inspector-heading-copy h2 {
    overflow: hidden;
    margin: 0;
    font-size: 17px;
    letter-spacing: -0.02em;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.registry-inspector-heading-copy h2:focus {
    outline: 0;
}

.registry-inspector-close {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    background: #fff;
    color: #625d54;
}

.registry-inspector-close:hover {
    border-color: #ada69b;
    color: #302c26;
}

.registry-inspector-close:focus-visible,
.registry-person-link:focus-visible {
    outline: 2px solid var(--ob-focus);
    outline-offset: 2px;
}

.registry-inspector-close svg {
    width: 15px;
    height: 15px;
}

.registry-inspector-body {
    flex: 1;
    overflow: auto;
    overscroll-behavior: contain;
    padding: 16px;
}

.registry-facts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px 12px;
    margin: 0;
}

.registry-facts dt {
    margin-bottom: 4px;
    color: #6f685f;
    font-size: 10px;
    font-weight: 650;
    text-transform: uppercase;
}

.registry-facts dd {
    overflow-wrap: anywhere;
    margin: 0;
    color: #302c26;
    font-size: 11px;
    font-weight: 580;
}

.registry-inspector-section {
    margin-top: 22px;
    padding-top: 16px;
    border-top: 1px solid var(--ob-border);
}

.registry-inspector-section h3 {
    margin: 0 0 9px;
    font-size: 12px;
}

.registry-section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.registry-section-heading span {
    display: grid;
    min-width: 22px;
    height: 22px;
    place-items: center;
    border-radius: 999px;
    background: #efede8;
    color: #6f685f;
    font-size: 9px;
    font-weight: 650;
}

.registry-person-link {
    display: grid;
    width: 100%;
    min-height: 58px;
    grid-template-columns: minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 8px;
    border: 0;
    border-bottom: 1px solid var(--ob-border-soft);
    padding: 8px 0;
    background: transparent;
    color: #625d54;
    text-align: left;
}

.registry-person-link:hover {
    background: var(--ob-accent-tint-soft);
}

.registry-activity {
    display: grid;
    grid-template-columns: 7px minmax(0, 1fr) auto;
    align-items: start;
    gap: 9px;
    padding: 9px 0;
}

.registry-activity + .registry-activity {
    border-top: 1px solid var(--ob-border-soft);
}

.registry-activity i {
    width: 7px;
    height: 7px;
    margin-top: 4px;
    border-radius: 50%;
    background: #777067;
}

.registry-activity strong,
.registry-activity small {
    display: block;
}

.registry-activity strong {
    font-size: 11px;
}

.registry-activity small,
.registry-activity time {
    color: #6b655c;
    font-size: 9px;
    text-transform: capitalize;
}

.registry-inspector-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 12px 15px;
    border-top: 1px solid var(--ob-border);
    background: #fff;
}

.registry-inspector-foot p {
    width: 100%;
    margin: 0 0 4px;
    color: #6b655c;
    font-size: 11px;
}

.intelligence-account-link {
    display: inline-flex;
    min-height: 36px;
    align-items: center;
    border: 1px solid var(--ob-accent);
    border-radius: 8px;
    padding: 7px 12px;
    background: var(--ob-accent-tint-soft);
    color: var(--ob-accent-strong, #28574b);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}

.intelligence-account-link:hover,
.intelligence-account-link:focus-visible {
    background: var(--ob-accent-tint, #dcefe8);
    text-decoration: underline;
}

.registry-inspector-foot button {
    min-height: 36px;
    border: 1px solid #d4cdc2;
    border-radius: 8px;
    padding: 7px 12px;
    background: #fff;
    color: #302c26;
}

.registry-inspector-foot button:disabled {
    border-color: #dedad3;
    background: #dedad3;
    color: #777168;
}

.registry-inspector-foot button.danger {
    border-color: #a43c35;
    background: #a43c35;
    color: #fff;
}

.registry-inspector-foot button.danger-quiet {
    border-color: #e4c8c4;
    color: #a43c35;
}

.registry-notes {
    margin: 0;
    color: #302c26;
    font-size: 12px;
    line-height: 1.5;
    white-space: pre-wrap;
}

.registry-tag-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0;
}

.registry-tag-list span {
    border-radius: 999px;
    padding: 3px 8px;
    background: #efede8;
    color: #4a453e;
    font-size: 11px;
}

.registry-empty-people {
    margin: 8px 0 0;
    color: #6b655c;
    font-size: 12px;
}

.registry-facts a,
.registry-account-link {
    border: 0;
    padding: 0;
    background: transparent;
    color: var(--ob-accent-strong);
    font: inherit;
    text-align: left;
}

.registry-inspector-scrim {
    position: fixed;
    inset: 0;
    z-index: 44;
    display: none;
    border: 0;
    background: rgb(26 24 20 / 42%);
    opacity: 0;
    visibility: hidden;
    transition:
        opacity 180ms ease,
        visibility 0s linear 180ms;
}

.registry-inspector-scrim.open {
    opacity: 1;
    visibility: visible;
    transition-delay: 0s;
}

@media (max-width: 980px) {
    .registry-inspector {
        top: 0;
        z-index: 70;
        width: min(430px, 62vw);
        max-width: calc(100vw - 72px);
        box-shadow: -18px 0 48px rgb(25 22 18 / 20%);
    }

    .registry-inspector-scrim {
        display: block;
    }
}

@media (max-width: 700px) {
    .registry-inspector {
        width: 100vw;
        max-width: none;
        border-left: 0;
        box-shadow: none;
    }

    .registry-inspector-scrim {
        display: none;
    }

    .registry-inspector-close,
    .registry-inspector-foot button {
        min-width: 44px;
        min-height: 44px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .registry-inspector,
    .registry-inspector-scrim {
        transition-duration: 0.01ms;
    }
}
</style>
