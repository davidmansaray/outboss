<script setup lang="ts">
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Inbox,
    Megaphone,
    Plus,
    Tag,
    Target,
    Users,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import CampaignExecution from './CampaignExecution.vue';
import { people } from './workflow';
import {
    addCampaign,
    addObjective,
    addOffer,
    newWorkspace,
    objectiveProgress,
    refreshDrafts,
    syncCampaign,
} from './workspace';
import type { Campaign, Objective, Offer } from './workspace';

type Area = 'objectives' | 'offers' | 'campaigns' | 'prospects' | 'inbox';
const workspace = ref(newWorkspace());
const area = ref<Area>('objectives');
const selectedId = ref('');
const panel = ref(0);
const error = ref('');
const editor = ref<'objective' | 'offer' | null>(null);
const editId = ref('');
const inline = ref(false);
const titleInput = ref<HTMLInputElement | null>(null);
let editorTrigger: HTMLElement | null = null;
function closeEditor() {
    editor.value = null;
    error.value = '';
    void nextTick(() => editorTrigger?.focus());
}
function containFocus(event: KeyboardEvent) {
    const controls = Array.from(
        (event.currentTarget as HTMLElement).querySelectorAll<HTMLElement>(
            'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled)',
        ),
    );
    const first = controls[0];
    const last = controls[controls.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
}
const objectiveForm = ref({
    name: '',
    target: 6,
    success: 'Qualified meeting booked',
    endsAt: '2026-10-04',
    budget: 150,
});
const offerForm = ref({
    name: '',
    promise: '',
    owner: 'Alex Morgan',
    capacity: 5,
    fulfilment: 'Human-led workshop',
});
const titles: Record<Area, string> = {
    objectives: 'Objectives',
    offers: 'Offers',
    campaigns: 'Campaigns',
    prospects: 'Prospects',
    inbox: 'Inbox',
};
const campaignSteps = [
    'Overview',
    'Audience',
    'Message',
    'Review & launch',
    'Conversations',
    'Outcomes',
];
const campaign = computed(() =>
    area.value === 'campaigns'
        ? workspace.value.campaigns.find((c) => c.id === selectedId.value)
        : undefined,
);
const objective = computed(() =>
    area.value === 'objectives'
        ? workspace.value.objectives.find((o) => o.id === selectedId.value)
        : undefined,
);
const offer = computed(() =>
    area.value === 'offers'
        ? workspace.value.offers.find((o) => o.id === selectedId.value)
        : undefined,
);
const linkedCampaigns = computed(() =>
    workspace.value.campaigns.filter((c) =>
        objective.value
            ? c.objectiveId === objective.value.id
            : offer.value
              ? c.offerId === offer.value.id
              : false,
    ),
);
const currentTitle = computed(
    () =>
        campaign.value?.name ||
        objective.value?.name ||
        offer.value?.name ||
        titles[area.value],
);
const inboxCampaigns = computed(() =>
    workspace.value.campaigns.filter((c) => c.workflow.launched),
);
function navigate(next: Area, id = '') {
    area.value = next;
    selectedId.value = id;
    panel.value = 0;
    error.value = '';
    void nextTick(() => {
        document.documentElement.scrollTop = 0;
        document.body.scrollTop = 0;
    });
}
function createCampaign(objectiveId = '', offerId = '') {
    const c = addCampaign(
        workspace.value,
        'Untitled campaign',
        objectiveId,
        offerId,
    );
    navigate('campaigns', c.id);
}
function openEditor(
    kind: 'objective' | 'offer',
    existing?: Objective | Offer,
    fromCampaign = false,
) {
    editorTrigger = document.activeElement as HTMLElement | null;
    editor.value = kind;
    editId.value = existing?.id ?? '';
    inline.value = fromCampaign;
    error.value = '';

    if (kind === 'objective') {
        objectiveForm.value = existing
            ? { ...(existing as Objective) }
            : {
                  name: '',
                  target: 6,
                  success: 'Qualified meeting booked',
                  endsAt: '2026-10-04',
                  budget: 150,
              };
    } else {
        offerForm.value = existing
            ? { ...(existing as Offer) }
            : {
                  name: '',
                  promise: '',
                  owner: 'Alex Morgan',
                  capacity: 5,
                  fulfilment: 'Human-led workshop',
              };
    }

    void nextTick(() => titleInput.value?.focus());
}
function saveEntity() {
    const w = workspace.value;
    let id = '';

    if (editor.value === 'objective') {
        const f = objectiveForm.value;

        if (
            !f.name.trim() ||
            !Number.isInteger(f.target) ||
            f.target < 1 ||
            !f.endsAt ||
            !Number.isFinite(f.budget) ||
            f.budget < 0
        ) {
            error.value =
                'Add a name, positive target, deadline and non-negative budget.';

            return;
        }

        const existing = w.objectives.find((o) => o.id === editId.value);

        if (existing) {
            Object.assign(existing, f, { version: existing.version + 1 });
            id = existing.id;
        } else {
            id = addObjective(w, { ...f, name: f.name.trim() }).id;
        }

        if (inline.value && campaign.value) {
            campaign.value.objectiveId = id;
        }
    } else {
        const f = offerForm.value;

        if (
            !f.name.trim() ||
            !f.promise.trim() ||
            !f.owner.trim() ||
            !Number.isInteger(f.capacity) ||
            f.capacity < 1
        ) {
            error.value = 'Add a name, promise, owner and positive capacity.';

            return;
        }

        const existing = w.offers.find((o) => o.id === editId.value);

        if (existing) {
            Object.assign(existing, f, { version: existing.version + 1 });
            id = existing.id;
        } else {
            id = addOffer(w, { ...f, name: f.name.trim() }).id;
        }

        if (inline.value && campaign.value) {
            campaign.value.offerId = id;
        }
    }

    refreshDrafts(w);
    const next = editor.value === 'objective' ? 'objectives' : 'offers';
    editor.value = null;
    error.value = '';

    if (!inline.value) {
        navigate(next, id);
    }
}
function goPanel(index: number) {
    if (!campaign.value || (index > 3 && !campaign.value.workflow.launched)) {
        return;
    }

    panel.value = index;
    error.value = '';
}
function continueCampaign() {
    const c = campaign.value;

    if (!c || !c.name.trim() || !c.objectiveId || !c.offerId) {
        error.value =
            'Name this campaign and select an Objective and an Offer.';

        return;
    }

    syncCampaign(workspace.value, c);
    goPanel(1);
}
function viewLinked(kind: 'offer' | 'objective') {
    if (!campaign.value) {
        return;
    }

    navigate(
        kind === 'offer' ? 'offers' : 'objectives',
        kind === 'offer' ? campaign.value.offerId : campaign.value.objectiveId,
    );
}
function status(c: Campaign) {
    return c.workflow.launched
        ? c.workflow.paused
            ? 'Paused'
            : 'Demo active'
        : c.workflow.approval
          ? 'Approved'
          : 'Draft';
}
function objectiveName(id: string) {
    return (
        workspace.value.objectives.find((o) => o.id === id)?.name ??
        'No Objective selected'
    );
}
function offerName(id: string) {
    return (
        workspace.value.offers.find((o) => o.id === id)?.name ??
        'No Offer selected'
    );
}
function campaignCount(kind: 'objective' | 'offer', id: string) {
    return workspace.value.campaigns.filter((c) =>
        kind === 'objective' ? c.objectiveId === id : c.offerId === id,
    ).length;
}
function reset() {
    workspace.value = newWorkspace();
    editor.value = null;
    navigate('objectives');
}
</script>

<template>
    <div class="human-workflow">
        <aside
            class="hw-global"
            aria-label="Workspace navigation"
            :inert="!!editor"
        >
            <img :src="'/outboss-wordmark.svg'" alt="OutBoss" class="hw-logo" />
            <span class="hw-nav-label">Work</span>
            <button
                :class="{ active: area === 'inbox' }"
                @click="navigate('inbox')"
            >
                <Inbox /><span>Inbox</span>
            </button>
            <button
                :class="{ active: area === 'campaigns' }"
                @click="navigate('campaigns')"
            >
                <Megaphone /><span>Outreach</span>
            </button>
            <span class="hw-nav-label">Build</span>
            <button
                :class="{ active: area === 'prospects' }"
                @click="navigate('prospects')"
            >
                <Users /><span>Prospects</span>
            </button>
            <button
                :class="{ active: area === 'objectives' }"
                @click="navigate('objectives')"
            >
                <Target /><span>Objectives</span>
            </button>
            <button
                :class="{ active: area === 'offers' }"
                @click="navigate('offers')"
            >
                <Tag /><span>Offers</span>
            </button>
            <div class="hw-workspace">
                <span class="hw-avatar">S</span
                ><span>Studio workspace<small>Sample workspace</small></span>
            </div>
        </aside>
        <div class="hw-shell" :inert="!!editor">
            <div class="hw-prototype">
                <span
                    >Workflow prototype · sample data · no real sends · resets
                    on reload</span
                ><button @click="reset">Reset demo</button>
            </div>
            <header class="hw-top">
                <span
                    >{{ titles[area]
                    }}<span v-if="selectedId" class="hw-divider">/</span
                    >{{ selectedId ? currentTitle : '' }}</span
                ><span class="hw-user"
                    ><span class="hw-avatar">AM</span>Alex ·
                    Human-controlled</span
                >
            </header>
            <nav
                class="entity-mobile-nav"
                aria-label="Mobile workspace navigation"
            >
                <button
                    v-for="item in [
                        'objectives',
                        'offers',
                        'campaigns',
                        'prospects',
                        'inbox',
                    ] as Area[]"
                    :key="item"
                    :aria-current="area === item ? 'page' : undefined"
                    @click="navigate(item)"
                >
                    {{ titles[item] }}
                </button>
            </nav>
            <div class="hw-workbench">
                <aside
                    class="hw-context entity-context"
                    aria-label="Context navigation"
                >
                    <template v-if="campaign"
                        ><button
                            class="hw-text-button"
                            @click="navigate('campaigns')"
                        >
                            <ArrowLeft /> All campaigns
                        </button>
                        <h2>{{ campaign.name }}</h2>
                        <span class="hw-status">{{ status(campaign) }}</span>
                        <nav>
                            <button
                                v-for="(name, index) in campaignSteps"
                                :key="name"
                                :class="{ current: panel === index }"
                                :aria-current="
                                    panel === index ? 'step' : undefined
                                "
                                :disabled="
                                    index > 3 && !campaign.workflow.launched
                                "
                                @click="goPanel(index)"
                            >
                                <span class="hw-step-icon">{{ index + 1 }}</span
                                >{{ name }}
                            </button>
                        </nav></template
                    >
                    <template v-else
                        ><p class="hw-eyebrow">{{ titles[area] }}</p>
                        <nav>
                            <button class="current" @click="navigate(area)">
                                All {{ titles[area].toLowerCase()
                                }}<span class="entity-count">{{
                                    area === 'objectives'
                                        ? workspace.objectives.length
                                        : area === 'offers'
                                          ? workspace.offers.length
                                          : area === 'campaigns'
                                            ? workspace.campaigns.length
                                            : area === 'prospects'
                                              ? people.length
                                              : inboxCampaigns.length
                                }}</span>
                            </button>
                        </nav>
                        <div class="hw-authority">
                            <component
                                :is="
                                    area === 'offers'
                                        ? Tag
                                        : area === 'objectives'
                                          ? Target
                                          : Megaphone
                                "
                            /><strong>{{
                                area === 'objectives'
                                    ? 'Outcomes, not campaigns'
                                    : area === 'offers'
                                      ? 'Define once. Reuse.'
                                      : 'Prepare each outreach'
                            }}</strong>
                            <p>
                                {{
                                    area === 'objectives'
                                        ? 'Several campaigns can contribute to the same Objective. Progress stays with the goal.'
                                        : area === 'offers'
                                          ? 'An Offer exists independently. Link it to a campaign when you’re ready to use it.'
                                          : 'Audiences, messages, senders and approvals belong to individual campaigns.'
                                }}
                            </p>
                        </div></template
                    >
                </aside>
                <main class="hw-main entity-main">
                    <p v-if="error && !editor" class="hw-alert" role="alert">
                        {{ error }}
                    </p>
                    <template v-if="area === 'objectives' && !objective">
                        <div class="hw-heading">
                            <div>
                                <p class="hw-eyebrow">Build / Objectives</p>
                                <h1>Objectives</h1>
                                <p>
                                    Define the outcome. Connect campaigns when
                                    you’re ready.
                                </p>
                            </div>
                            <button
                                class="hw-primary"
                                @click="openEditor('objective')"
                            >
                                <Plus /> New objective
                            </button>
                        </div>
                        <div class="hw-table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Objective</th>
                                        <th>Progress</th>
                                        <th>Deadline</th>
                                        <th>Campaigns</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="o in workspace.objectives"
                                        :key="o.id"
                                    >
                                        <td>
                                            <button
                                                class="entity-row-link"
                                                @click="
                                                    navigate('objectives', o.id)
                                                "
                                            >
                                                {{ o.name }}</button
                                            ><span>{{ o.success }}</span>
                                        </td>
                                        <td>
                                            <strong
                                                >{{
                                                    objectiveProgress(
                                                        workspace,
                                                        o.id,
                                                    )
                                                }}
                                                / {{ o.target }}</strong
                                            >
                                            <div class="entity-progress">
                                                <span
                                                    :style="{
                                                        width:
                                                            Math.min(
                                                                100,
                                                                (objectiveProgress(
                                                                    workspace,
                                                                    o.id,
                                                                ) /
                                                                    o.target) *
                                                                    100,
                                                            ) + '%',
                                                    }"
                                                ></span>
                                            </div>
                                        </td>
                                        <td>{{ o.endsAt }}</td>
                                        <td>
                                            {{
                                                campaignCount('objective', o.id)
                                            }}
                                            linked
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="entity-list-note">
                            Objectives don’t need an audience or an Offer to
                            exist. Those choices happen when you create a
                            campaign.
                        </p>
                    </template>
                    <template v-else-if="objective">
                        <button
                            class="hw-text-button entity-back"
                            @click="navigate('objectives')"
                        >
                            <ArrowLeft /> All objectives
                        </button>
                        <div class="hw-heading">
                            <div>
                                <p class="hw-eyebrow">
                                    Objective · v{{ objective.version }}
                                </p>
                                <h1>{{ objective.name }}</h1>
                                <p>
                                    One goal. Progress across all contributing
                                    campaigns.
                                </p>
                            </div>
                            <button
                                class="hw-secondary"
                                @click="openEditor('objective', objective)"
                            >
                                Edit objective
                            </button>
                        </div>
                        <div class="entity-facts">
                            <div>
                                <span>Progress</span
                                ><strong
                                    >{{
                                        objectiveProgress(
                                            workspace,
                                            objective.id,
                                        )
                                    }}
                                    / {{ objective.target }}</strong
                                ><small>{{ objective.success }}</small>
                            </div>
                            <div>
                                <span>Deadline</span
                                ><strong>{{ objective.endsAt }}</strong
                                ><small>Shared by every campaign</small>
                            </div>
                            <div>
                                <span>Spend ceiling</span
                                ><strong>${{ objective.budget }}</strong
                                ><small
                                    >Across this Objective · $0 used in
                                    demo</small
                                >
                            </div>
                        </div>
                        <div class="hw-toolbar">
                            <div>
                                <h3>Contributing campaigns</h3>
                                <span
                                    >New campaigns continue this goal; they
                                    don’t reset its progress.</span
                                >
                            </div>
                            <button
                                class="hw-primary"
                                @click="createCampaign(objective.id)"
                            >
                                <Plus /> Create campaign
                            </button>
                        </div>
                        <div
                            v-if="!linkedCampaigns.length"
                            class="entity-empty"
                        >
                            <Megaphone />
                            <h3>No campaigns yet</h3>
                            <p>
                                This Objective is saved in the demo. Create a
                                campaign whenever you’re ready to work toward
                                it.
                            </p>
                        </div>
                        <div
                            v-for="c in linkedCampaigns"
                            :key="c.id"
                            class="entity-linked-row"
                        >
                            <button
                                class="entity-row-link"
                                @click="navigate('campaigns', c.id)"
                            >
                                {{ c.name
                                }}<small>{{
                                    offerName(c.offerId)
                                }}</small></button
                            ><span class="hw-status">{{ status(c) }}</span
                            ><span>{{
                                c.workflow.converted
                                    ? '1 recorded booking'
                                    : 'No bookings yet'
                            }}</span
                            ><button
                                class="hw-text-button"
                                @click="navigate('campaigns', c.id)"
                            >
                                Open campaign <ArrowRight />
                            </button>
                        </div>
                        <p class="entity-list-note">
                            Progress counts unique sample booking evidence, not
                            emails, replies, or duplicate recordings across
                            campaigns.
                        </p>
                    </template>
                    <template v-else-if="area === 'offers' && !offer">
                        <div class="hw-heading">
                            <div>
                                <p class="hw-eyebrow">Build / Offers</p>
                                <h1>Offers</h1>
                                <p>
                                    Maintain the promises you can deliver. Reuse
                                    them across campaigns.
                                </p>
                            </div>
                            <button
                                class="hw-primary"
                                @click="openEditor('offer')"
                            >
                                <Plus /> New offer
                            </button>
                        </div>
                        <div class="hw-table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Offer</th>
                                        <th>Fulfilment owner</th>
                                        <th>Capacity</th>
                                        <th>Campaigns</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="o in workspace.offers"
                                        :key="o.id"
                                    >
                                        <td>
                                            <button
                                                class="entity-row-link"
                                                @click="
                                                    navigate('offers', o.id)
                                                "
                                            >
                                                {{ o.name }}</button
                                            ><span
                                                >{{ o.fulfilment }} · v{{
                                                    o.version
                                                }}</span
                                            >
                                        </td>
                                        <td>{{ o.owner }}</td>
                                        <td>{{ o.capacity }} slots</td>
                                        <td>
                                            {{ campaignCount('offer', o.id) }}
                                            linked
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="entity-list-note">
                            An Offer is not tied to one Objective. Choose it
                            from any campaign without recreating it.
                        </p>
                    </template>
                    <template v-else-if="offer">
                        <button
                            class="hw-text-button entity-back"
                            @click="navigate('offers')"
                        >
                            <ArrowLeft /> All offers
                        </button>
                        <div class="hw-heading">
                            <div>
                                <p class="hw-eyebrow">
                                    Reusable Offer · v{{ offer.version }}
                                </p>
                                <h1>{{ offer.name }}</h1>
                                <p>{{ offer.promise }}</p>
                            </div>
                            <button
                                class="hw-secondary"
                                @click="openEditor('offer', offer)"
                            >
                                Edit offer
                            </button>
                        </div>
                        <div class="entity-facts">
                            <div>
                                <span>Delivery</span
                                ><strong>{{ offer.fulfilment }}</strong>
                            </div>
                            <div>
                                <span>Responsible person</span
                                ><strong>{{ offer.owner }}</strong>
                            </div>
                            <div>
                                <span>Capacity</span
                                ><strong>{{ offer.capacity }} slots</strong
                                ><small>Shared fulfilment availability</small>
                            </div>
                        </div>
                        <div class="hw-toolbar">
                            <div>
                                <h3>Campaigns using this Offer</h3>
                                <span
                                    >The same Offer can support different
                                    Objectives.</span
                                >
                            </div>
                            <button
                                class="hw-primary"
                                @click="createCampaign('', offer.id)"
                            >
                                <Plus /> Create campaign
                            </button>
                        </div>
                        <div
                            v-if="!linkedCampaigns.length"
                            class="entity-empty"
                        >
                            <Megaphone />
                            <h3>Ready to use when you are</h3>
                            <p>
                                No campaign is required to keep this Offer in
                                your library.
                            </p>
                        </div>
                        <div
                            v-for="c in linkedCampaigns"
                            :key="c.id"
                            class="entity-linked-row"
                        >
                            <button
                                class="entity-row-link"
                                @click="navigate('campaigns', c.id)"
                            >
                                {{ c.name
                                }}<small>{{
                                    objectiveName(c.objectiveId)
                                }}</small></button
                            ><span class="hw-status">{{ status(c) }}</span
                            ><button
                                class="hw-text-button"
                                @click="navigate('campaigns', c.id)"
                            >
                                Open campaign <ArrowRight />
                            </button>
                        </div>
                    </template>
                    <template v-else-if="area === 'campaigns' && !campaign">
                        <div class="hw-heading">
                            <div>
                                <p class="hw-eyebrow">Outreach</p>
                                <h1>Campaigns</h1>
                                <p>
                                    Connect an Objective and Offer, then prepare
                                    the audience and outreach.
                                </p>
                            </div>
                            <button
                                class="hw-primary"
                                @click="createCampaign()"
                            >
                                <Plus /> New campaign
                            </button>
                        </div>
                        <div
                            v-if="!workspace.campaigns.length"
                            class="entity-empty"
                        >
                            <Megaphone />
                            <h2>No campaigns yet</h2>
                            <p>
                                Your Objectives and Offers are already
                                available. Create a campaign to put them to
                                work.
                            </p>
                            <button
                                class="hw-secondary"
                                @click="createCampaign()"
                            >
                                Create your first campaign <ArrowRight />
                            </button>
                        </div>
                        <div v-else class="hw-table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Campaign</th>
                                        <th>Objective / Offer</th>
                                        <th>Audience</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="c in workspace.campaigns"
                                        :key="c.id"
                                    >
                                        <td>
                                            <button
                                                class="entity-row-link"
                                                @click="
                                                    navigate('campaigns', c.id)
                                                "
                                            >
                                                {{ c.name }}
                                            </button>
                                        </td>
                                        <td>
                                            {{ objectiveName(c.objectiveId)
                                            }}<span>{{
                                                offerName(c.offerId)
                                            }}</span>
                                        </td>
                                        <td>
                                            {{
                                                c.workflow.draft.selected.length
                                            }}
                                            selected
                                        </td>
                                        <td>
                                            <span class="hw-status">{{
                                                status(c)
                                            }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <template v-else-if="campaign">
                        <div class="hw-heading">
                            <div>
                                <p class="hw-eyebrow">
                                    Campaign / {{ campaignSteps[panel] }}
                                </p>
                                <h1>
                                    {{
                                        panel === 0
                                            ? campaign.name
                                            : campaignSteps[panel]
                                    }}
                                </h1>
                                <p>
                                    {{
                                        panel === 0
                                            ? 'Assemble this campaign from existing building blocks.'
                                            : 'These choices and results belong to this campaign.'
                                    }}
                                </p>
                            </div>
                            <span class="hw-status">{{
                                status(campaign)
                            }}</span>
                        </div>
                        <template v-if="panel === 0">
                            <p
                                v-if="campaign.workflow.launched"
                                class="hw-notice"
                            >
                                Approved snapshot · read-only. Subsequent
                                library edits do not rewrite this campaign.
                            </p>
                            <div class="hw-columns">
                                <fieldset
                                    :disabled="campaign.workflow.launched"
                                    class="hw-form"
                                >
                                    <label
                                        >Campaign name<input
                                            v-model="campaign.name"
                                            placeholder="e.g. Agency founders · September"
                                    /></label>
                                    <div class="entity-picker">
                                        <label
                                            >Objective<select
                                                v-model="campaign.objectiveId"
                                                @change="
                                                    syncCampaign(
                                                        workspace,
                                                        campaign,
                                                    )
                                                "
                                            >
                                                <option value="">
                                                    Select an Objective
                                                </option>
                                                <option
                                                    v-for="o in workspace.objectives"
                                                    :key="o.id"
                                                    :value="o.id"
                                                >
                                                    {{ o.name }}
                                                </option>
                                            </select></label
                                        ><button
                                            class="hw-text-button"
                                            @click="
                                                openEditor(
                                                    'objective',
                                                    undefined,
                                                    true,
                                                )
                                            "
                                        >
                                            <Plus /> Create new objective
                                        </button>
                                        <p class="hw-help">
                                            Reuse an existing goal. Its target
                                            and progress stay in Objectives.
                                        </p>
                                    </div>
                                    <div class="entity-picker">
                                        <label
                                            >Offer<select
                                                v-model="campaign.offerId"
                                                @change="
                                                    syncCampaign(
                                                        workspace,
                                                        campaign,
                                                    )
                                                "
                                            >
                                                <option value="">
                                                    Select an Offer
                                                </option>
                                                <option
                                                    v-for="o in workspace.offers"
                                                    :key="o.id"
                                                    :value="o.id"
                                                >
                                                    {{ o.name }}
                                                </option>
                                            </select></label
                                        ><button
                                            class="hw-text-button"
                                            @click="
                                                openEditor(
                                                    'offer',
                                                    undefined,
                                                    true,
                                                )
                                            "
                                        >
                                            <Plus /> Create new offer
                                        </button>
                                        <p class="hw-help">
                                            Choose a reusable promise. Inline
                                            creation adds it to the Offer
                                            library.
                                        </p>
                                    </div>
                                </fieldset>
                                <aside class="hw-inspector">
                                    <p class="hw-eyebrow">
                                        Linked records{{
                                            campaign.workflow.launched
                                                ? ' · approved snapshot'
                                                : ''
                                        }}
                                    </p>
                                    <dl>
                                        <dt>Objective</dt>
                                        <dd>
                                            {{
                                                campaign.workflow.draft
                                                    .objective ||
                                                'Not selected'
                                            }}<small
                                                v-if="campaign.objectiveId"
                                            >
                                                · v{{
                                                    campaign.workflow.draft
                                                        .objectiveVersion
                                                }}</small
                                            >
                                        </dd>
                                        <dt>Deadline</dt>
                                        <dd>
                                            {{
                                                campaign.workflow.draft
                                                    .endsAt || 'Not set'
                                            }}
                                        </dd>
                                        <dt>Offer</dt>
                                        <dd>
                                            {{
                                                campaign.workflow.draft.offer ||
                                                'Not selected'
                                            }}<small v-if="campaign.offerId">
                                                · v{{
                                                    campaign.workflow.draft
                                                        .offerVersion
                                                }}</small
                                            >
                                        </dd>
                                        <dt>Promise</dt>
                                        <dd>
                                            {{
                                                campaign.workflow.draft
                                                    .promise ||
                                                'Choose an Offer to see its promise.'
                                            }}
                                        </dd>
                                    </dl>
                                    <button
                                        v-if="campaign.objectiveId"
                                        class="hw-text-button"
                                        @click="viewLinked('objective')"
                                    >
                                        Open Objective <ArrowRight /></button
                                    ><br /><button
                                        v-if="campaign.offerId"
                                        class="hw-text-button"
                                        @click="viewLinked('offer')"
                                    >
                                        Open Offer <ArrowRight />
                                    </button>
                                </aside>
                            </div>
                            <footer class="hw-footer">
                                <span
                                    >Draft kept in this tab · safe to switch
                                    sections</span
                                ><button
                                    class="hw-primary"
                                    @click="continueCampaign()"
                                >
                                    Continue to Audience <ArrowRight />
                                </button>
                            </footer>
                        </template>
                        <CampaignExecution
                            v-else
                            :key="campaign.id + ':' + panel"
                            :workflow="campaign.workflow"
                            :step="panel + 1"
                            @navigate="goPanel($event - 1)"
                            @entity="viewLinked"
                        />
                    </template>
                    <template v-else-if="area === 'prospects'">
                        <div class="hw-heading">
                            <div>
                                <p class="hw-eyebrow">Build / Inventory</p>
                                <h1>Prospects</h1>
                                <p>
                                    Reusable people and accounts. Inclusion in
                                    outreach is a campaign decision.
                                </p>
                            </div>
                        </div>
                        <div class="hw-table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Person</th>
                                        <th>Company</th>
                                        <th>Eligibility</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in people" :key="p.id">
                                        <td>
                                            <strong>{{ p.name }}</strong
                                            ><span>{{ p.email }}</span>
                                        </td>
                                        <td>{{ p.company }}</td>
                                        <td>
                                            {{
                                                p.excluded ||
                                                'Eligible in sample'
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="entity-list-note">
                            Select an audience inside a campaign. This library
                            does not enrol people or send anything.
                        </p>
                        <button
                            class="hw-secondary"
                            @click="navigate('campaigns')"
                        >
                            Open campaigns <ArrowRight />
                        </button>
                    </template>
                    <template v-else-if="area === 'inbox'">
                        <div class="hw-heading">
                            <div>
                                <p class="hw-eyebrow">Work / Inbox</p>
                                <h1>Conversations</h1>
                                <p>
                                    Follow through on outreach while retaining
                                    the campaign context.
                                </p>
                            </div>
                        </div>
                        <div v-if="!inboxCampaigns.length" class="entity-empty">
                            <Inbox />
                            <h3>No campaign conversations yet</h3>
                            <p>
                                Launch a demo campaign to explore replies and
                                booking outcomes.
                            </p>
                        </div>
                        <div
                            v-for="c in inboxCampaigns"
                            :key="c.id"
                            class="entity-linked-row"
                        >
                            <div>
                                <strong>{{ c.name }}</strong>
                                <p>
                                    {{
                                        c.workflow.replied
                                            ? 'Interested reply received'
                                            : 'Ready to simulate a reply'
                                    }}
                                </p>
                            </div>
                            <button
                                class="hw-secondary"
                                @click="
                                    navigate('campaigns', c.id);
                                    panel = 4;
                                "
                            >
                                Open conversation <ArrowRight />
                            </button>
                        </div>
                    </template>
                </main>
            </div>
        </div>
        <div
            v-if="editor"
            class="entity-editor-backdrop"
            @keydown.esc="closeEditor"
            @keydown.tab="containFocus"
        >
            <section
                role="dialog"
                aria-modal="true"
                :aria-label="(editId ? 'Edit ' : 'New ') + editor"
                class="entity-editor"
            >
                <div class="hw-heading">
                    <div>
                        <p class="hw-eyebrow">
                            {{
                                inline
                                    ? 'Create once · reuse anywhere'
                                    : 'Independent record'
                            }}
                        </p>
                        <h2>{{ editId ? 'Edit' : 'New' }} {{ editor }}</h2>
                    </div>
                    <button
                        class="hw-text-button"
                        aria-label="Close editor"
                        @click="closeEditor"
                    >
                        Close
                    </button>
                </div>
                <p v-if="error" class="hw-alert" role="alert">{{ error }}</p>
                <form @submit.prevent="saveEntity">
                    <template v-if="editor === 'objective'"
                        ><label
                            >Objective name<input
                                ref="titleInput"
                                v-model="objectiveForm.name"
                                required /></label
                        ><label
                            >Success event<select
                                v-model="objectiveForm.success"
                            >
                                <option>Qualified meeting booked</option>
                                <option>Workshop booked</option>
                            </select></label
                        >
                        <div class="hw-fields">
                            <label
                                >Target bookings<input
                                    v-model.number="objectiveForm.target"
                                    type="number"
                                    min="1"
                                    required /></label
                            ><label
                                >Deadline<input
                                    v-model="objectiveForm.endsAt"
                                    type="date"
                                    required
                            /></label>
                        </div>
                        <label
                            >Objective spend ceiling (USD)<input
                                v-model.number="objectiveForm.budget"
                                type="number"
                                min="0"
                                required
                        /></label>
                        <p class="hw-help">
                            This target and deadline apply across contributing
                            campaigns—not separately to each one.
                        </p></template
                    >
                    <template v-else
                        ><label
                            >Offer name<input
                                ref="titleInput"
                                v-model="offerForm.name"
                                required /></label
                        ><label
                            >Promise<textarea
                                v-model="offerForm.promise"
                                rows="3"
                                required
                            /></label
                        ><label
                            >Delivery<select v-model="offerForm.fulfilment">
                                <option>Human-led workshop</option>
                                <option>Human-delivered audit</option>
                            </select></label
                        >
                        <div class="hw-fields">
                            <label
                                >Responsible person<input
                                    v-model="offerForm.owner"
                                    required /></label
                            ><label
                                >Available slots<input
                                    v-model.number="offerForm.capacity"
                                    type="number"
                                    min="1"
                                    required
                            /></label>
                        </div>
                        <p class="hw-help">
                            Capacity belongs to this Offer, not to each campaign
                            that uses it.
                        </p></template
                    >
                    <p v-if="editId" class="hw-notice">
                        Saving creates the next demo version. Unlaunched drafts
                        refresh and require fresh approval; launched snapshots
                        stay unchanged.
                    </p>
                    <footer class="hw-footer">
                        <button
                            type="button"
                            class="hw-text-button"
                            @click="closeEditor"
                        >
                            Cancel</button
                        ><button class="hw-primary" type="submit">
                            <Check />{{
                                inline ? 'Create and select' : 'Save ' + editor
                            }}
                        </button>
                    </footer>
                </form>
            </section>
        </div>
    </div>
</template>

<style scoped>
.entity-count {
    margin-left: auto;
    font-size: 11px;
}
.entity-row-link {
    background: transparent;
    border: 0;
    padding: 0;
    text-align: left;
    font-weight: 500;
    color: var(--ob-ink);
    line-height: 1.6;
}
.entity-row-link:hover {
    text-decoration: underline;
    text-underline-offset: 4px;
}
.entity-row-link small {
    display: block;
    font-weight: 400;
}
.entity-list-note {
    font-size: 12px;
    color: var(--muted);
    margin-top: 24px;
    max-width: 620px;
}
.entity-empty {
    padding: 45px 25px;
    border-block: 1px solid var(--line);
    text-align: center;
    display: flex;
    align-items: center;
    flex-direction: column;
    gap: 16px;
}
.entity-empty > svg {
    width: 28px;
    height: 28px;
    color: var(--muted);
}
.entity-empty p {
    max-width: 420px;
    color: var(--muted);
    font-size: 12px;
}
.entity-facts {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    border-block: 1px solid var(--line);
    margin-bottom: 32px;
    padding: 25px 0;
    gap: 24px;
}
.entity-facts > div {
    display: flex;
    flex-direction: column;
    gap: 9px;
}
.entity-facts span {
    font-size: 11px;
    color: var(--muted);
}
.entity-facts strong {
    font-size: 20px;
    font-weight: 500;
}
.entity-facts small {
    font-size: 10px;
}
.entity-back {
    margin-bottom: 20px;
}
.entity-progress {
    height: 3px;
    width: 80px;
    background: var(--line);
    margin-top: 8px;
}
.entity-progress span {
    display: block;
    height: 100%;
    background: var(--ob-ink);
    transition: width 0.25s;
}
.entity-linked-row {
    display: flex;
    align-items: center;
    gap: 20px;
    border-top: 1px solid var(--line);
    padding: 20px 0;
    font-size: 12px;
}
.entity-linked-row > :first-child {
    flex: 1;
}
.entity-picker {
    border-top: 1px solid var(--line);
    padding-top: 23px;
}
.entity-picker label {
    margin-bottom: 5px;
}
.entity-picker .hw-help {
    margin-top: 5px;
}
.entity-editor-backdrop {
    position: fixed;
    inset: 0;
    z-index: 20;
    background: #15151444;
    display: flex;
    justify-content: flex-end;
}
.entity-editor {
    width: 490px;
    max-width: 100%;
    background: white;
    padding: 30px;
    overflow-y: auto;
    box-shadow: -10px 0 40px #00000012;
    animation: editor-in 0.18s ease;
}
.entity-editor .hw-heading {
    margin-bottom: 30px;
}
.entity-mobile-nav {
    display: none;
}
@keyframes editor-in {
    from {
        opacity: 0.5;
        transform: translateX(12px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}
@media (max-width: 640px) {
    .entity-mobile-nav {
        display: flex;
        overflow-x: auto;
        border-bottom: 1px solid var(--line);
        padding: 6px 12px;
        gap: 5px;
    }
    .entity-mobile-nav button {
        background: none;
        border: 0;
        padding: 9px 7px;
        font-size: 11px;
    }
    .entity-mobile-nav button[aria-current='page'] {
        background: var(--ob-accent-tint);
        border-radius: 4px;
    }
    .entity-context .hw-authority {
        display: none;
    }
    .entity-facts {
        grid-template-columns: 1fr;
        gap: 18px;
    }
    .entity-facts strong {
        font-size: 18px;
    }
    .entity-linked-row {
        flex-wrap: wrap;
        gap: 12px;
    }
    .entity-linked-row > :first-child {
        flex-basis: 100%;
    }
    .entity-editor {
        padding: 24px 20px;
    }
    .entity-main .hw-heading {
        flex-wrap: wrap;
    }
    .entity-main table {
        min-width: 540px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .entity-editor {
        animation: none;
    }
    .entity-progress span {
        transition: none;
    }
}
</style>
