<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    Check,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    Mail,
    MoreHorizontal,
    Pause,
    Pencil,
    Play,
    Plus,
    Send,
    Users,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    watchEffect,
} from 'vue';
import CampaignCompositionForm from '@/components/campaigns/CampaignCompositionForm.vue';
import type { Composition } from '@/components/campaigns/CampaignCompositionForm.vue';
import CampaignEnrollmentDialog from '@/components/campaigns/CampaignEnrollmentDialog.vue';
import CampaignImportRows from '@/components/campaigns/CampaignImportRows.vue';
import SequenceStepPreview from '@/components/campaigns/SequenceStepPreview.vue';
import AppWorkspace from '@/layouts/AppWorkspace.vue';
import { csvTable, guessCampaignImportMapping } from '@/lib/parseCsv';

type Person = {
    id: number;
    person_id: number | null;
    name: string | null;
    email: string;
    company: string | null;
    status: string;
    lifecycle: string;
    mailbox: string | null;
    mailbox_id: number | null;
    first_sent_at: string | null;
    thread_id: number | null;
    sequence_version: number | null;
};

type Step = {
    id: number;
    title: string;
    delay_minutes: number;
    send_as: string;
    subject: string | null;
    body: string;
    subject_spintax_enabled?: boolean;
    body_spintax_enabled?: boolean;
};

type CampaignTab = 'overview' | 'audience' | 'sequence' | 'sending';
type AudienceFilter = 'all' | 'ready' | 'in_progress' | 'replied' | 'stopped';
type AddPeopleMode = 'registry' | 'paste';
type EnrollmentFlash = {
    enrolled: number;
    skipped: { person_id: number | null; name: string; reason: string }[];
    notice?: string;
};

type LaunchBlocker = {
    id: string;
    section: 'audience' | 'sequence' | 'sending';
    label: string;
    message: string;
};

type ImportReview = {
    id: number;
    mapping_version: number;
    ready: number;
    invalid: number;
    duplicate: number;
    suppressed: number;
};

const MAX_CAMPAIGN_IMPORT_BYTES = 5 * 1024 * 1024;
const MAX_CAMPAIGN_IMPORT_ROWS = 10000;

const props = defineProps<{
    composition?: Composition;
    campaign: {
        id: number;
        name: string;
        status: 'draft' | 'active' | 'paused' | 'completed';
        send_mode: string;
        daily_send_limit?: number | null;
        people: Person[];
        steps: Step[];
        mailboxes: number[];
        mailbox_emails: string[];
        timezone: string;
        send_window_start: string;
        send_window_end: string;
        business_days_only: boolean;
        open_tracking_enabled: boolean;
        updated_at: string | null;
        launched_at: string | null;
        editable: boolean;
        has_draft: boolean;
        sequence_issues: { code: string; message: string }[];
        supported_variables: string[];
        sequence: {
            draft_version: number | null;
            published_version: number | null;
            versions_count: number;
            unstarted_count: number;
        };
    };
    readiness: {
        ready: boolean;
        blockers: LaunchBlocker[];
    };
    outcomes: {
        queued: number;
        sent: number;
        replied: number;
        interested: number;
        bounced: number;
        unsubscribed: number;
        needs_review: number;
    };
    availableMailboxes: {
        id: number;
        email_address: string;
        status: string;
        effective_reply_identity?: {
            email: string;
            name: string | null;
        } | null;
        sending_paused?: boolean;
        sending_paused_reason?: string | null;
        daily_send_cap?: number;
        remaining_today?: number;
    }[];
}>();

const page = usePage();
function tabFromUrl(): CampaignTab {
    const value =
        typeof window === 'undefined'
            ? null
            : new URL(window.location.href).searchParams.get('step');

    return ['overview', 'audience', 'sequence', 'sending'].includes(value ?? '')
        ? (value as CampaignTab)
        : 'overview';
}
const tab = ref<CampaignTab>(tabFromUrl());
const audienceFilter = ref<AudienceFilter>('all');
const paste = ref('');
const importFilename = ref<string | null>(null);
const importParseError = ref('');
const importHeaders = ref<string[]>([]);
const importRows = ref<Record<string, string>[]>([]);
const importMapping = ref({ email: '', name: '', first_name: '', company: '' });
const importStep = ref<'source' | 'mapping'>('source');
let importFileRequestId = 0;
const selectedStep = ref(props.campaign.steps[0]?.id ?? null);
const sequenceMode = ref<'edit' | 'preview'>('edit');
const previewPersonId = ref<number | null>(
    props.campaign.people[0]?.id ?? null,
);

watch(
    () => props.campaign.people.map((person) => person.id),
    (ids) => {
        if (ids.length === 0) {
            previewPersonId.value = null;

            return;
        }

        if (
            previewPersonId.value === null ||
            !ids.includes(previewPersonId.value)
        ) {
            previewPersonId.value = ids[0];
        }
    },
);
const showAddPeople = ref(false);
const addPeopleMode = ref<AddPeopleMode>('registry');
const enrollmentDialog = ref<{
    focusSearch?: () => void | Promise<void>;
} | null>(null);
const showImport = ref(false);
const importError = ref('');
const importPending = ref(false);
const showLaunch = ref(false);
const showRename = ref(false);
const renameTrigger = ref<HTMLButtonElement | null>(null);
const renameDialog = ref<HTMLElement | null>(null);
const renameInput = ref<HTMLInputElement | null>(null);
type ConfirmDialogState = {
    title: string;
    body: string;
    confirmLabel: string;
    danger?: boolean;
    onConfirm: () => void;
};
const confirmDialog = ref<ConfirmDialogState | null>(null);
const confirmDialogEl = ref<HTMLElement | null>(null);
const confirmTrigger = ref<HTMLElement | null>(null);
const addPeopleTrigger = ref<HTMLButtonElement | null>(null);
const addPeopleDialog = ref<HTMLElement | null>(null);
const importDialog = ref<HTMLElement | null>(null);
const pasteInput = ref<HTMLTextAreaElement | null>(null);
const launchTrigger = ref<HTMLButtonElement | null>(null);
const launchDialog = ref<HTMLElement | null>(null);
const testTo = ref('');
const testMailbox = ref(
    props.availableMailboxes.find(
        (box) => box.status === 'connected' && box.effective_reply_identity,
    )?.id ?? 0,
);
const live = ref('');
const campaignActionPending = ref(false);
const inferredDailySendLimit = (() => {
    if (
        props.campaign.daily_send_limit !== null &&
        props.campaign.daily_send_limit !== undefined
    ) {
        return props.campaign.daily_send_limit;
    }

    if (props.campaign.status !== 'draft') {
        return null;
    }

    const selectedCapacity = props.availableMailboxes
        .filter(
            (box) =>
                props.campaign.mailboxes.includes(box.id) &&
                box.status === 'connected' &&
                Boolean(box.effective_reply_identity) &&
                !box.sending_paused,
        )
        .reduce(
            (total, box) =>
                total + Math.max(0, Number(box.daily_send_cap ?? 0)),
            0,
        );

    return Math.max(1, selectedCapacity || 50);
})();

const sending = useForm({
    send_mode: props.campaign.send_mode,
    daily_send_limit: inferredDailySendLimit,
    automatic_allowance: props.campaign.status === 'draft',
    mailbox_ids: props.campaign.mailboxes,
    send_window_start: props.campaign.send_window_start.slice(0, 5),
    send_window_end: props.campaign.send_window_end.slice(0, 5),
    business_days_only: props.campaign.business_days_only,
    open_tracking_enabled: props.campaign.open_tracking_enabled,
});
const renameForm = useForm({
    name: props.campaign.name,
});

const importReview = ref<ImportReview | null>(
    ((page.props.flash as { import?: ImportReview })?.import ??
        null) as ImportReview | null,
);
watch(
    () => (page.props.flash as { import?: ImportReview })?.import,
    (review) => {
        if (review) {
            importReview.value = review;
        }
    },
);
const enrollmentResult = computed(
    () =>
        (
            page.props.flash as {
                enrollment?: EnrollmentFlash;
            }
        )?.enrollment ?? null,
);
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const importValidationError = computed(() => {
    const entry = Object.entries(pageErrors.value).find(
        ([key]) =>
            key === 'rows' ||
            key.startsWith('rows.') ||
            key.startsWith('mapping'),
    );

    return entry?.[1] ?? '';
});
const currentStep = computed(
    () =>
        props.campaign.steps.find((step) => step.id === selectedStep.value) ??
        props.campaign.steps[0],
);
type EmailDraft = {
    title: string;
    subject_template: string;
    body_template: string;
};
const emailDrafts = ref<Record<number, EmailDraft>>({});
const emailSavePending = ref(false);
const emailSaveError = ref('');
const emailSaveStatus = ref('');
function savedEmail(step: Step): EmailDraft {
    return {
        title: step.title,
        subject_template: step.subject ?? '',
        body_template: step.body,
    };
}
watchEffect(
    () => {
        const step = currentStep.value;

        if (step && !emailDrafts.value[step.id]) {
            emailDrafts.value[step.id] = savedEmail(step);
        }
    },
    { flush: 'sync' },
);
const emailDraft = computed(() => {
    const step = currentStep.value;

    if (!step) {
        return { title: '', subject_template: '', body_template: '' };
    }

    return emailDrafts.value[step.id];
});
const emailDirty = computed(() =>
    props.campaign.steps.some(
        (step) =>
            emailDrafts.value[step.id] &&
            JSON.stringify(emailDrafts.value[step.id]) !==
                JSON.stringify(savedEmail(step)),
    ),
);
function saveEmail(continueTo?: CampaignTab) {
    if (
        !currentStep.value ||
        emailSavePending.value ||
        !props.campaign.editable
    ) {
        return;
    }

    emailSavePending.value = true;
    emailSaveError.value = '';
    emailSaveStatus.value = '';
    const id = currentStep.value.id;
    let saved = false;
    router.patch(
        `/sequence-steps/${id}`,
        { ...emailDraft.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                saved = true;
                delete emailDrafts.value[id];
                emailSaveStatus.value =
                    'Email saved. Your latest text is ready for preview and review.';
                live.value = emailSaveStatus.value;
            },
            onError: (errors) => {
                emailSaveError.value =
                    Object.values(errors)[0] ??
                    'Could not save this email. Your text is still here; correct the issue and try again.';
            },
            onFinish: () => {
                emailSavePending.value = false;

                if (saved && continueTo) {
                    void setTab(continueTo);
                }
            },
        },
    );
}
const audienceGroups: Record<Exclude<AudienceFilter, 'all'>, string[]> = {
    ready: ['queued'],
    in_progress: ['active', 'paused', 'blocked_mailbox'],
    replied: ['replied', 'interested', 'not_now', 'wrong_person'],
    stopped: ['completed', 'bounced', 'unsubscribed', 'removed'],
};

const audienceFilters = [
    { id: 'all', label: 'All' },
    { id: 'ready', label: 'Ready' },
    { id: 'in_progress', label: 'In progress' },
    { id: 'replied', label: 'Replied' },
    { id: 'stopped', label: 'Stopped' },
] as const;

const filteredPeople = computed(() => {
    if (audienceFilter.value === 'all') {
        return props.campaign.people;
    }

    return props.campaign.people.filter((person) =>
        audienceGroups[
            audienceFilter.value as Exclude<AudienceFilter, 'all'>
        ].includes(person.lifecycle),
    );
});

const sendablePeople = computed(
    () =>
        props.campaign.people.filter((person) =>
            ['queued', 'active'].includes(person.lifecycle),
        ).length,
);
const excludedPeople = computed(() =>
    Math.max(0, props.campaign.people.length - sendablePeople.value),
);
const audienceReadinessDetail = computed(() => {
    const ready = `${sendablePeople.value} ${sendablePeople.value === 1 ? 'person' : 'people'} ready to receive`;

    return excludedPeople.value > 0
        ? `${ready}; ${excludedPeople.value} excluded by status or eligibility`
        : ready;
});
const selectedMailboxes = computed(() =>
    props.availableMailboxes.filter((box) =>
        props.campaign.mailboxes.includes(box.id),
    ),
);
const isReadyMailbox = (box: (typeof props.availableMailboxes)[number]) =>
    box.status === 'connected' &&
    Boolean(box.effective_reply_identity) &&
    !box.sending_paused;
const readyMailboxes = computed(() =>
    props.availableMailboxes.filter(isReadyMailbox),
);
const selectedSendingMailboxes = computed(() =>
    props.availableMailboxes.filter((box) =>
        sending.mailbox_ids.includes(box.id),
    ),
);
const selectedReadyMailboxes = computed(() =>
    selectedSendingMailboxes.value.filter(isReadyMailbox),
);
const unselectedReadyMailboxes = computed(() =>
    readyMailboxes.value.filter((box) => !sending.mailbox_ids.includes(box.id)),
);
function mailboxDailyCap(box: (typeof props.availableMailboxes)[number]) {
    return Math.max(0, Number(box.daily_send_cap ?? 0));
}
function mailboxRemainingToday(box: (typeof props.availableMailboxes)[number]) {
    return Math.max(0, Number(box.remaining_today ?? 0));
}
const selectedInboxDailyCapacity = computed(() =>
    selectedReadyMailboxes.value.reduce(
        (total, box) => total + mailboxDailyCap(box),
        0,
    ),
);
const selectedInboxRemainingToday = computed(() =>
    selectedReadyMailboxes.value.reduce(
        (total, box) => total + mailboxRemainingToday(box),
        0,
    ),
);
const requestedDailyLimit = computed(() => {
    const value = Number(sending.daily_send_limit);

    return Number.isFinite(value) && value > 0 ? value : null;
});
const dailyLimitShortfall = computed(() =>
    requestedDailyLimit.value === null
        ? 0
        : Math.max(
              0,
              requestedDailyLimit.value - selectedInboxDailyCapacity.value,
          ),
);
const needsAutomaticAllowance = computed(
    () =>
        Boolean(props.composition) &&
        props.campaign.status === 'draft' &&
        props.campaign.editable &&
        !props.composition?.execution_authority_scope_id,
);
const needsSuggestedDailyLimitSave = computed(
    () =>
        props.campaign.status === 'draft' &&
        props.campaign.editable &&
        (props.campaign.daily_send_limit === null ||
            props.campaign.daily_send_limit === undefined) &&
        Number(sending.daily_send_limit) > 0,
);
const sendingNeedsSave = computed(
    () =>
        sending.isDirty ||
        needsAutomaticAllowance.value ||
        needsSuggestedDailyLimitSave.value,
);
function useReadyInboxes() {
    if (
        !props.campaign.editable ||
        unselectedReadyMailboxes.value.length === 0
    ) {
        return;
    }

    sending.mailbox_ids = Array.from(
        new Set([
            ...sending.mailbox_ids,
            ...unselectedReadyMailboxes.value.map((box) => box.id),
        ]),
    );
}
function lowerDailyTargetToCapacity() {
    if (!props.campaign.editable || selectedInboxDailyCapacity.value <= 0) {
        return;
    }

    sending.daily_send_limit = selectedInboxDailyCapacity.value;
}
const testMailboxReady = computed(() =>
    props.availableMailboxes.some(
        (box) =>
            box.id === testMailbox.value &&
            box.status === 'connected' &&
            Boolean(box.effective_reply_identity),
    ),
);

const audienceControllable = computed(() =>
    ['draft', 'active', 'paused'].includes(props.campaign.status),
);
const canAddPeople = computed(() => audienceControllable.value);

const readinessSections = [
    {
        id: 'audience' as const,
        label: 'Audience',
        readyDetail: () => audienceReadinessDetail.value,
    },
    {
        id: 'sequence' as const,
        label: 'Sequence',
        readyDetail: () =>
            props.campaign.steps.length +
            (props.campaign.steps.length === 1
                ? ' email saved — review before sending'
                : ' emails saved — review before sending'),
    },
    {
        id: 'sending' as const,
        label: 'Sending',
        readyDetail: () =>
            selectedMailboxes.value.length +
            (selectedMailboxes.value.length === 1
                ? ' sender ready'
                : ' senders ready'),
    },
];

const launchChecks = computed(() => {
    const selectedOffer = props.composition?.offers.find(
        (offer) => offer.id === props.composition?.offer_version_id,
    );
    const unavailableOffer =
        selectedOffer &&
        (!selectedOffer.current || selectedOffer.status !== 'active');
    const unavailableGoal =
        selectedGoal.value?.status &&
        !['draft', 'ready', 'active'].includes(selectedGoal.value.status);
    const goalNeedsMeasurement =
        props.composition?.goals.find(
            (goal) => goal.id === props.composition?.objective_goal_version_id,
        )?.measurement_configured === false;
    const compositionChecks = props.composition
        ? [
              {
                  id: 'composition',
                  tab: 'overview' as CampaignTab,
                  label: 'Objective and Offer',
                  ready: Boolean(
                      props.composition.objective_goal_version_id &&
                      props.composition.offer_version_id &&
                      !goalNeedsMeasurement &&
                      !unavailableOffer &&
                      !unavailableGoal,
                  ),
                  detail: unavailableGoal
                      ? 'The selected Objective is not available for new campaigns. Open it to resolve its status or choose another Objective.'
                      : unavailableOffer
                        ? 'The selected Offer must be active and use its current version. Complete or review the Offer, then select its current version.'
                        : goalNeedsMeasurement && updatedSelectedGoal.value
                          ? 'The measurement is already configured in a newer Objective version. Choose the updated version; it saves automatically.'
                          : goalNeedsMeasurement
                            ? 'Open the Objective and complete What counts, then select its updated version.'
                            : props.composition.objective_goal_version_id &&
                                props.composition.offer_version_id
                              ? 'Selections saved. Review the selected Offer before sending.'
                              : 'Choose or create an Objective and an Offer. Selections save automatically.',
              },
              {
                  id: 'schedule',
                  tab: 'sending' as CampaignTab,
                  label: 'Sending allowance and capacity',
                  ready:
                      Boolean(props.composition.execution_authority_scope_id) &&
                      ![
                          'over_capacity',
                          'daily_limit_exceeded',
                          'unsupported',
                          'unavailable',
                          'allowance_exceeded',
                      ].includes(
                          props.composition.sending_forecast?.status ?? '',
                      ),
                  detail: !props.composition.execution_authority_scope_id
                      ? 'Save sending to calculate a bounded sending permission from your audience and inboxes. Start and deadline dates are optional.'
                      : (props.composition.sending_forecast?.message ??
                        'Run until finished, or set an optional deadline.'),
              },
          ]
        : [];

    return [
        ...compositionChecks.filter((check) => check.id === 'composition'),
        ...readinessSections.map((section) => {
            const blocker = props.readiness.blockers.find(
                (item) =>
                    item.section === section.id &&
                    (!props.composition || item.label !== 'Approval'),
            );

            return {
                id: section.id as CampaignTab,
                tab: section.id as CampaignTab,
                label: section.label,
                ready: !blocker,
                detail: blocker?.message ?? section.readyDetail(),
            };
        }),
        ...compositionChecks.filter((check) => check.id === 'schedule'),
        ...(props.composition
            ? [
                  {
                      id: 'approval',
                      tab: 'overview' as CampaignTab,
                      label: 'Exact review and approval',
                      ready: !props.readiness.blockers.some(
                          (item) => item.label === 'Approval',
                      ),
                      detail: 'Review the exact recipients and messages, approve them, then launch as a separate step.',
                  },
              ]
            : []),
    ];
});
const compositionSection = ref<HTMLElement | null>(null);
const compositionFeedback = ref<'composition' | 'schedule' | null>(null);
const compositionDirty = ref(false);
const compositionForm = ref<{ ensureSaved: () => Promise<boolean> } | null>(
    null,
);
const selectedGoal = computed(() =>
    props.composition?.goals.find(
        (goal) => goal.id === props.composition?.objective_goal_version_id,
    ),
);
const updatedSelectedGoal = computed(() =>
    props.composition?.goals.find(
        (goal) =>
            goal.objective_id === selectedGoal.value?.objective_id &&
            goal.current &&
            goal.measurement_configured === true,
    ),
);
function setupActionLabel(check: { id: string; label: string }) {
    if (check.id === 'composition') {
        return selectedGoal.value?.measurement_configured === false &&
            updatedSelectedGoal.value
            ? 'Choose updated Objective'
            : selectedGoal.value?.measurement_configured === false
              ? 'Complete Objective measurement'
              : 'Choose Objective and Offer';
    }

    if (check.id === 'schedule') {
        return props.composition?.execution_authority_scope_id
            ? 'Review sending capacity'
            : 'Save and continue';
    }

    return check.id === 'approval'
        ? 'Review exact messages'
        : `Set up ${check.label.toLowerCase()}`;
}
async function openSetupCheck(check: { id: string; tab: CampaignTab }) {
    compositionFeedback.value =
        check.id === 'composition' || check.id === 'schedule' ? check.id : null;

    if (check.id === 'approval') {
        if (emailDirty.value || emailSavePending.value) {
            setTab('sequence');
            live.value =
                'Save email before reviewing exact messages. Your latest email changes have not been saved yet.';

            return;
        }

        if (
            compositionForm.value &&
            !(await compositionForm.value.ensureSaved())
        ) {
            setTab('overview');
            compositionFeedback.value = 'composition';
            live.value =
                'Your setup has not been saved. Check the save status and retry before reviewing exact messages.';

            return;
        }

        if (sendingNeedsSave.value) {
            setTab('sending');
            live.value =
                'Save sending setup to calculate capacity and prepare the sending permission before reviewing exact messages.';

            return;
        }

        router.visit(
            `/campaigns/${props.campaign.id}/review${!nextSetupRequirement.value && props.campaign.status === 'draft' ? '?prepare=1' : ''}`,
        );

        return;
    }

    if (
        check.id === 'composition' &&
        selectedGoal.value?.measurement_configured === false &&
        !updatedSelectedGoal.value
    ) {
        router.visit(
            `/objectives/${selectedGoal.value.objective_id}?from_campaign=${props.campaign.id}`,
        );

        return;
    }

    await setTab(check.tab);

    if (check.tab === tab.value && check.tab !== 'overview') {
        await nextTick();
        const target = document.querySelector<HTMLElement>(
            check.id === 'sending'
                ? '#daily-send-limit'
                : '.campaign-section input:not([disabled]), .campaign-section select:not([disabled]), .campaign-section textarea:not([disabled])',
        );
        target?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
        target?.focus({ preventScroll: true });
    }

    if (check.tab === 'overview' || check.id === 'schedule') {
        await nextTick();
        const field =
            check.id === 'schedule'
                ? '#daily-send-limit'
                : selectedGoal.value?.measurement_configured === false &&
                    updatedSelectedGoal.value
                  ? '#campaign-update-goal'
                  : !props.composition?.objective_goal_version_id ||
                      (selectedGoal.value?.status &&
                          !['draft', 'ready', 'active'].includes(
                              selectedGoal.value.status,
                          ))
                    ? '#campaign-goal'
                    : '#campaign-offer';
        const target =
            document.querySelector<HTMLElement>(field) ??
            compositionSection.value?.querySelector<HTMLElement>(field) ??
            compositionSection.value;
        target?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
        target?.focus({ preventScroll: true });
    }
}
const launchReady = computed(
    () =>
        props.readiness.ready &&
        launchChecks.value.every((check) => check.ready),
);
const nextIncompleteCheck = computed(() =>
    launchChecks.value.find((check) => !check.ready),
);
const nextSetupRequirement = computed(() =>
    launchChecks.value.find((check) => !check.ready && check.id !== 'approval'),
);
async function primarySetupAction() {
    if (nextSetupRequirement.value) {
        await openSetupCheck(nextSetupRequirement.value);
    } else {
        await openSetupCheck({ id: 'approval', tab: 'overview' });
    }
}
const setupProgress = computed(() => {
    const current = launchChecks.value.find((check) => check.id === tab.value);

    if (tab.value === 'audience') {
        return {
            step: 2,
            title: 'Choose who to contact',
            detail: current?.ready
                ? 'Your audience is saved. Next, write the email they will receive.'
                : (current?.detail ??
                  'Add people to this campaign before continuing.'),
            label: current?.ready ? 'Continue to Sequence' : 'Add people',
        };
    }

    if (tab.value === 'sequence') {
        return {
            step: 3,
            title: 'Write your email',
            detail: emailDirty.value
                ? 'Your email has unsaved changes. Save it before continuing to Sending.'
                : current?.ready
                  ? 'Next, choose your sending inbox and schedule. Nothing sends until you approve and launch.'
                  : (current?.detail ?? 'Complete your email below.'),
            label: emailDirty.value
                ? 'Save and continue to Sending'
                : current?.ready
                  ? 'Continue to Sending'
                  : 'Complete email',
        };
    }

    const missing = launchChecks.value.find(
        (check) => !check.ready && check.id !== 'approval',
    );

    return {
        step: 4,
        title: 'Prepare to review',
        detail: sendingNeedsSave.value
            ? 'Save sending to apply inbox choices and calculate the campaign capacity before review.'
            : (missing?.detail ??
              'Review the exact recipients and messages next. Approval and launch are separate actions.'),
        label: sendingNeedsSave.value
            ? 'Save and continue'
            : missing
              ? setupActionLabel(missing)
              : 'Review exact messages',
    };
});
async function continueSetup() {
    if (tab.value === 'audience') {
        if (
            !launchChecks.value.find((check) => check.id === 'audience')?.ready
        ) {
            openAddPeople();
        } else {
            await setTab('sequence');
        }
    } else if (tab.value === 'sequence') {
        if (emailDirty.value) {
            saveEmail('sending');
        } else if (
            !launchChecks.value.find((check) => check.id === 'sequence')?.ready
        ) {
            const input = document.querySelector<HTMLElement>(
                '.sequence-fields input[aria-label="Subject"], .sequence-fields textarea',
            );
            input?.focus();
            input?.scrollIntoView?.({ block: 'center' });
        } else {
            await setTab('sending');
        }
    } else if (sendingNeedsSave.value) {
        saveSending(true);
    } else {
        const missing = launchChecks.value.find(
            (check) => !check.ready && check.id !== 'approval',
        );

        if (missing) {
            await openSetupCheck(missing);
        } else if (props.composition) {
            await openSetupCheck({ id: 'approval', tab: 'overview' });
        } else {
            await openLaunchReview();
        }
    }
}

const waitOptions = [
    { value: 0, label: 'Immediate' },
    { value: 2880, label: '2 days' },
    { value: 4320, label: '3 days' },
    { value: 5760, label: '4 days' },
    { value: 7200, label: '5 days' },
    { value: 10080, label: '7 days' },
];

const sequenceVersionLabel = computed(() => {
    const sequence = props.campaign.sequence;
    const draft = sequence.draft_version;
    const published = sequence.published_version;

    if (props.campaign.has_draft && draft && published) {
        return `Editing draft v${draft} — v${published} is live`;
    }

    if (published && props.campaign.status !== 'draft') {
        return `v${published} is live`;
    }

    return `Draft v${draft ?? 1}`;
});

const unstartedMoveCopy = computed(() => {
    const count = props.campaign.sequence.unstarted_count;

    if (count === 1) {
        return '1 recipient who has not started will move';
    }

    return `${count} recipients who have not started will move`;
});

function senderLabel(box: (typeof props.availableMailboxes)[number]): string {
    const identity = box.effective_reply_identity;

    if (!identity) {
        return box.email_address;
    }

    return identity.name
        ? identity.name + ' <' + identity.email + '>'
        : identity.email;
}

function mailboxSendingStatus(
    box: (typeof props.availableMailboxes)[number],
): string {
    if (box.sending_paused) {
        return 'Sending paused';
    }

    if (box.status !== 'connected') {
        return 'Disconnected';
    }

    return box.effective_reply_identity ? 'Ready' : 'Identity unavailable';
}

function campaignStatusLabel(): string {
    return {
        draft: 'Draft',
        active: 'Active',
        paused: 'Paused',
        completed: 'Completed',
    }[props.campaign.status];
}

function campaignDescription(): string {
    if (props.campaign.status === 'active') {
        return 'Sending is active. Human replies stop later sequence emails.';
    }

    if (props.campaign.status === 'paused') {
        return 'Sending is paused. Review any changes before resuming.';
    }

    if (props.campaign.status === 'completed') {
        return sendablePeople.value > 0
            ? 'Completed manually with unfinished people. Review before continuing their messages.'
            : 'This campaign has finished sending. Review replies and outcomes.';
    }

    return 'Choose your Objective and Offer, add people, write your message, then configure sending and review.';
}

function personStatusLabel(status: string): string {
    return (
        {
            queued: 'Ready',
            active: 'In sequence',
            paused: 'Paused',
            blocked_mailbox: 'Mailbox blocked',
            completed: 'Completed',
            replied: 'Replied',
            interested: 'Interested',
            not_now: 'Not now',
            wrong_person: 'Wrong person',
            bounced: 'Delivery issue',
            unsubscribed: 'Opted out',
            removed: 'Removed',
        }[status] ?? status.replaceAll('_', ' ')
    );
}

function personStatusTone(status: string): string {
    if (status === 'interested') {
        return 'positive';
    }

    if (['bounced', 'blocked_mailbox'].includes(status)) {
        return 'negative';
    }

    if (['queued', 'active', 'replied'].includes(status)) {
        return 'attention';
    }

    return 'muted';
}

function audienceFilterCount(filterId: AudienceFilter): number {
    if (filterId === 'all') {
        return props.campaign.people.length;
    }

    return props.campaign.people.filter((person) =>
        audienceGroups[filterId].includes(person.lifecycle),
    ).length;
}

function peopleLabel(count: number): string {
    return count === 1 ? 'person' : 'people';
}

async function openConfirmDialog(
    state: ConfirmDialogState,
    trigger?: HTMLElement | null,
) {
    confirmTrigger.value = trigger ?? (document.activeElement as HTMLElement);
    confirmDialog.value = state;
    await nextTick();
    confirmDialogEl.value
        ?.querySelector<HTMLElement>(
            '[data-test="campaign-confirm-accept"], button.btn:not(.ghost)',
        )
        ?.focus();
}

async function closeConfirmDialog() {
    confirmDialog.value = null;
    await nextTick();
    confirmTrigger.value?.focus();
    confirmTrigger.value = null;
}

function acceptConfirmDialog() {
    const action = confirmDialog.value?.onConfirm;
    confirmDialog.value = null;
    action?.();
}

async function setTab(nextTab: CampaignTab) {
    if (
        tab.value === 'sequence' &&
        nextTab !== 'sequence' &&
        (emailDirty.value || emailSavePending.value)
    ) {
        live.value =
            'Save email before leaving Sequence. Your latest email changes have not been saved yet.';

        return;
    }

    if (
        nextTab !== tab.value &&
        compositionForm.value &&
        !(await compositionForm.value.ensureSaved())
    ) {
        live.value =
            'Your setup has not been saved. Check the save status and retry before leaving Overview.';

        return;
    }

    tab.value = nextTab;
    const url = new URL(window.location.href);
    url.searchParams.set('step', nextTab);
    window.history.replaceState(window.history.state, '', url);
    await nextTick();
    const heading = document.querySelector<HTMLElement>(
        '.campaign-setup-progress h2, .campaign-section h2',
    );
    heading?.setAttribute('tabindex', '-1');
    heading?.focus({ preventScroll: true });
    heading?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
}

function formatEnrollmentSummary(result: EnrollmentFlash): string {
    const enrolledLabel = result.enrolled === 1 ? 'person' : 'people';
    const parts = [`Added ${result.enrolled} ${enrolledLabel}.`];

    if (result.skipped.length === 0) {
        return result.notice ? `${parts[0]} ${result.notice}` : parts[0];
    }

    const counts: Record<string, number> = {};

    for (const skip of result.skipped) {
        counts[skip.reason] = (counts[skip.reason] ?? 0) + 1;
    }

    const bits: string[] = [];

    if (counts.Suppressed) {
        bits.push(`${counts.Suppressed} suppressed`);
    }

    if (counts['Already in campaign']) {
        bits.push(`${counts['Already in campaign']} already in this campaign`);
    }

    if (counts['Not found']) {
        bits.push(`${counts['Not found']} not found`);
    }

    if (counts['Invalid email']) {
        bits.push(`${counts['Invalid email']} invalid`);
    }

    parts.push(`${result.skipped.length} skipped: ${bits.join(', ')}.`);

    if (result.notice) {
        parts.push(result.notice);
    }

    return parts.join(' ');
}

async function openAddPeople() {
    if (props.campaign.status === 'completed') {
        await openLaunchReview();

        return;
    }

    addPeopleMode.value = 'registry';
    showAddPeople.value = true;
    await nextTick();
    await enrollmentDialog.value?.focusSearch?.();
}

async function setAddPeopleMode(mode: AddPeopleMode) {
    addPeopleMode.value = mode;

    if (mode === 'paste') {
        importFileRequestId++;
        importFilename.value = null;
        importStep.value = 'source';
        importParseError.value = '';
    }

    await nextTick();

    if (mode === 'registry') {
        await enrollmentDialog.value?.focusSearch?.();
    } else {
        pasteInput.value?.focus();
    }
}

function handleEnrolled() {
    showAddPeople.value = false;
    live.value = enrollmentResult.value
        ? formatEnrollmentSummary(enrollmentResult.value)
        : 'People added to the campaign.';
}

async function closeAddPeople() {
    if (importPending.value) {
        return;
    }

    showAddPeople.value = false;
    await nextTick();
    addPeopleTrigger.value?.focus();
}

function previewPeople() {
    if (importPending.value) {
        return;
    }

    importPending.value = true;
    importError.value = '';
    router.post(
        '/campaigns/' + props.campaign.id + '/recipient-imports/preview',
        {
            source: importFilename.value ? 'csv' : 'paste',
            rows: importRows.value,
            mapping: importMapping.value,
            filename: importFilename.value,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                importError.value =
                    Object.values(errors)[0] ??
                    'Could not review this import. Try again.';
            },
            onFinish: () => {
                importPending.value = false;
            },
            onSuccess: () => {
                showAddPeople.value = false;
                showImport.value = true;
                void nextTick(() => importDialog.value?.focus());
            },
        },
    );
}

function applyImportTable(text: string, filename: string | null): void {
    if (new Blob([text]).size > MAX_CAMPAIGN_IMPORT_BYTES) {
        importParseError.value = 'CSV files must be 5 MB or smaller.';
        importHeaders.value = [];
        importRows.value = [];

        return;
    }

    const table = csvTable(text, ['email', 'name', 'company']);

    if (!table.rows.length) {
        importParseError.value = 'Add at least one data row to import.';
        importHeaders.value = [];
        importRows.value = [];

        return;
    }

    if (table.rows.length > MAX_CAMPAIGN_IMPORT_ROWS) {
        importParseError.value = 'CSV imports are limited to 10,000 rows.';
        importHeaders.value = [];
        importRows.value = [];

        return;
    }

    if (
        new Blob([JSON.stringify(table.rows)]).size > MAX_CAMPAIGN_IMPORT_BYTES
    ) {
        importParseError.value = 'Parsed CSV data must be 5 MB or smaller.';
        importHeaders.value = [];
        importRows.value = [];

        return;
    }

    importParseError.value = '';
    importFilename.value = filename;
    importHeaders.value = table.headers;
    importRows.value = table.rows;
    importMapping.value = guessCampaignImportMapping(table.headers);
    importStep.value = 'mapping';
}

function continueImport(): void {
    applyImportTable(paste.value, null);
}

function onImportFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    const requestId = ++importFileRequestId;
    addPeopleMode.value = 'paste';
    importStep.value = 'source';

    if (file.size > MAX_CAMPAIGN_IMPORT_BYTES) {
        importParseError.value = 'CSV files must be 5 MB or smaller.';

        return;
    }

    void file
        .text()
        .then((contents) => {
            if (requestId !== importFileRequestId) {
                return;
            }

            paste.value = contents;
            applyImportTable(contents, file.name);
        })
        .catch(() => {
            if (requestId === importFileRequestId) {
                importParseError.value = 'Could not read that CSV file.';
            }
        });
}

function samplePeople() {
    paste.value =
        'email,name,company\nmaya@stripe.com,Maya Chen,Stripe\naisha@sequoia.com,Aisha Rahman,Sequoia';
    applyImportTable(paste.value, null);
}

function commitImport() {
    if (!importReview.value?.id || importPending.value) {
        return;
    }

    importError.value = '';
    importPending.value = true;
    const reviewedImport = importReview.value;

    router.post(
        '/campaigns/' +
            props.campaign.id +
            '/recipient-imports/' +
            reviewedImport.id +
            '/commit',
        { expected_mapping_version: reviewedImport.mapping_version ?? 1 },
        {
            preserveScroll: true,
            onFinish: () => {
                importPending.value = false;
            },
            onSuccess: () => {
                showImport.value = false;
                paste.value = '';
                importFilename.value = null;
                importHeaders.value = [];
                importRows.value = [];
                importMapping.value = {
                    email: '',
                    name: '',
                    first_name: '',
                    company: '',
                };
                importStep.value = 'source';
                live.value = 'People added to the campaign.';
            },
            onError: (errors) => {
                importError.value =
                    errors.import ??
                    Object.values(errors)[0] ??
                    'Could not add the reviewed people.';
                showImport.value = true;
                void nextTick(() => importDialog.value?.focus());
            },
        },
    );
}

async function closeImportReview() {
    if (importPending.value) {
        return;
    }

    showImport.value = false;
    await nextTick();
    addPeopleTrigger.value?.focus();
}

async function returnToAddPeople() {
    if (importPending.value) {
        return;
    }

    showImport.value = false;
    showAddPeople.value = true;
    await nextTick();
    pasteInput.value?.focus();
}

function updateStep(field: string, value: string | number | boolean) {
    if (emailDirty.value || emailSavePending.value) {
        live.value = 'Save email before changing email settings.';

        return;
    }

    if (!currentStep.value) {
        return;
    }

    router.patch(
        '/sequence-steps/' + currentStep.value.id,
        { [field]: value },
        {
            preserveScroll: true,
            onError: (errors) => {
                const message = Object.values(errors)[0];

                if (typeof message === 'string') {
                    live.value = message;
                }
            },
        },
    );
}

function moveStep(index: number, direction: -1 | 1) {
    const next = index + direction;

    if (next < 0 || next >= props.campaign.steps.length) {
        return;
    }

    const stepIds = props.campaign.steps.map((step) => step.id);
    const [moved] = stepIds.splice(index, 1);
    stepIds.splice(next, 0, moved);

    router.put(
        '/campaigns/' + props.campaign.id + '/steps/order',
        { step_ids: stepIds },
        { preserveScroll: true },
    );
}

const testSendPending = ref(false);
const testSendError = ref('');
function sendTest() {
    if (emailDirty.value || emailSavePending.value) {
        live.value =
            'Save email before sending a test so the test uses your latest text.';

        return;
    }

    if (testSendPending.value) {
        return;
    }

    testSendError.value = '';

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(testTo.value.trim())) {
        testSendError.value = 'Enter a valid email address for your test.';

        return;
    }

    if (!currentStep.value || !testMailboxReady.value) {
        testSendError.value =
            'Choose a connected sending mailbox before sending a test.';

        return;
    }

    testSendPending.value = true;
    router.post(
        '/sequence-steps/' + currentStep.value.id + '/test',
        {
            mailbox_id: testMailbox.value,
            to: testTo.value.trim(),
            client_operation_id: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () => (live.value = 'Test email sent.'),
            onError: (errors) => {
                testSendError.value =
                    Object.values(errors).join(' ') ||
                    'The test could not be sent. Check the address and mailbox.';
            },
            onFinish: () => (testSendPending.value = false),
        },
    );
}

function saveSending(continueAfter = false, afterSave?: () => void) {
    if (sending.processing) {
        return;
    }

    if (props.campaign.status === 'draft') {
        sending.automatic_allowance = true;
    }

    let saved = false;
    sending.patch('/campaigns/' + props.campaign.id, {
        preserveScroll: true,
        onSuccess: () => {
            saved = true;
            live.value = 'Sending setup saved.';
        },
        onFinish: () => {
            if (!saved) {
                return;
            }

            if (afterSave) {
                afterSave();
            } else if (continueAfter) {
                void primarySetupAction();
            }
        },
    });
}

function openMailboxSettings() {
    const url = `/settings/mailboxes?from_campaign=${props.campaign.id}`;

    if (!sendingNeedsSave.value) {
        router.visit(url);

        return;
    }

    saveSending(false, () => router.visit(url));
}

async function openLaunchReview() {
    showLaunch.value = true;
    await nextTick();
    launchDialog.value?.focus();
}

async function closeLaunchReview() {
    if (campaignActionPending.value) {
        return;
    }

    showLaunch.value = false;
    await nextTick();
    launchTrigger.value?.focus();
}

async function reviewLaunchCheck(nextTab: CampaignTab) {
    setTab(nextTab);
    await closeLaunchReview();
}

function launchCampaign() {
    if (!launchReady.value || campaignActionPending.value) {
        return;
    }

    const resuming =
        props.campaign.status === 'paused' ||
        props.campaign.status === 'completed';

    campaignActionPending.value = true;
    router.post(
        resuming
            ? '/campaigns/' + props.campaign.id + '/resume'
            : '/campaigns/' + props.campaign.id + '/launch',
        {},
        {
            onSuccess: () => {
                showLaunch.value = false;
                live.value = resuming
                    ? 'Campaign resumed.'
                    : 'Campaign launched.';
            },
            onFinish: () => (campaignActionPending.value = false),
        },
    );
}

function pauseCampaign() {
    if (campaignActionPending.value) {
        return;
    }

    campaignActionPending.value = true;
    router.post(
        '/campaigns/' + props.campaign.id + '/pause',
        {},
        {
            onSuccess: () => (live.value = 'Campaign paused.'),
            onFinish: () => (campaignActionPending.value = false),
        },
    );
}

function completeCampaign(event?: Event) {
    if (campaignActionPending.value) {
        return;
    }

    void openConfirmDialog(
        {
            title: 'Mark campaign complete?',
            body: 'Completing stops all future sends. You can resume later; history is kept.',
            confirmLabel: 'Mark complete',
            onConfirm: () => {
                campaignActionPending.value = true;
                router.post(
                    '/campaigns/' + props.campaign.id + '/complete',
                    {},
                    {
                        onSuccess: () => (live.value = 'Campaign completed.'),
                        onFinish: () => (campaignActionPending.value = false),
                    },
                );
            },
        },
        event?.currentTarget instanceof HTMLElement
            ? event.currentTarget
            : null,
    );
}

async function openRename() {
    renameForm.name = props.campaign.name;
    renameForm.clearErrors();
    showRename.value = true;
    await nextTick();
    renameInput.value?.focus();
    renameInput.value?.select();
}

async function closeRename() {
    if (renameForm.processing) {
        return;
    }

    showRename.value = false;
    await nextTick();
    renameTrigger.value?.focus();
}

function submitRename() {
    renameForm.patch('/campaigns/' + props.campaign.id, {
        preserveScroll: true,
        onSuccess: () => {
            showRename.value = false;
            live.value = 'Campaign renamed.';
        },
    });
}

function removePerson(personId: number, event?: Event) {
    void openConfirmDialog(
        {
            title: 'Remove from campaign?',
            body:
                props.campaign.status === 'active'
                    ? 'Removing stops all future emails to this person. Their history is kept.'
                    : 'This person will leave the campaign. Their history is kept.',
            confirmLabel: 'Remove person',
            danger: true,
            onConfirm: () => {
                router.delete('/campaign-recipients/' + personId);
            },
        },
        event?.currentTarget instanceof HTMLElement
            ? event.currentTarget
            : null,
    );
}

function deleteCurrentStep(event?: Event) {
    if (!currentStep.value) {
        return;
    }

    const stepId = currentStep.value.id;
    void openConfirmDialog(
        {
            title: 'Delete this email?',
            body: 'This removes the step from the sequence draft. People who already received it keep their history.',
            confirmLabel: 'Delete email',
            danger: true,
            onConfirm: () => {
                router.delete('/sequence-steps/' + stepId);
            },
        },
        event?.currentTarget instanceof HTMLElement
            ? event.currentTarget
            : null,
    );
}

function keepFocusInDialog(event: KeyboardEvent, dialog: HTMLElement | null) {
    if (event.key !== 'Tab' || !dialog) {
        return;
    }

    const focusable = Array.from(
        dialog.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    );

    if (!focusable.length) {
        event.preventDefault();
        dialog.focus();

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

function handleKeydown(event: KeyboardEvent) {
    const openDialog = confirmDialog.value
        ? confirmDialogEl.value
        : showRename.value
          ? renameDialog.value
          : showLaunch.value
            ? launchDialog.value
            : showImport.value
              ? importDialog.value
              : showAddPeople.value
                ? addPeopleDialog.value
                : null;

    keepFocusInDialog(event, openDialog);

    if (event.key !== 'Escape') {
        return;
    }

    if (confirmDialog.value) {
        event.preventDefault();
        void closeConfirmDialog();
    } else if (showRename.value) {
        event.preventDefault();
        void closeRename();
    } else if (showLaunch.value) {
        event.preventDefault();
        void closeLaunchReview();
    } else if (showImport.value) {
        event.preventDefault();
        void closeImportReview();
    } else if (showAddPeople.value) {
        event.preventDefault();
        void closeAddPeople();
    }
}

const hasUnsavedChanges = computed(
    () =>
        emailDirty.value ||
        sending.isDirty ||
        compositionDirty.value ||
        emailSavePending.value ||
        sending.processing,
);
function warnBeforeUnload(event: BeforeUnloadEvent) {
    if (!hasUnsavedChanges.value) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}
let removeNavigationGuard: (() => void) | undefined;
onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
    window.addEventListener('beforeunload', warnBeforeUnload);
    removeNavigationGuard = router.on?.('before', (event) => {
        if (event.detail.visit.method !== 'get' || !hasUnsavedChanges.value) {
            return;
        }

        if (
            !window.confirm(
                'You have unsaved campaign changes. Leave and discard them? Cancel to stay and save.',
            )
        ) {
            event.preventDefault();
        }
    });
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('beforeunload', warnBeforeUnload);
    removeNavigationGuard?.();
});
</script>

<template>
    <Head :title="campaign.name" />
    <div v-if="live" class="campaign-feedback" role="status">{{ live }}</div>
    <AppWorkspace area="campaigns" :title="campaign.name">
        <template #sidebar>
            <Link class="campaign-back" href="/campaigns">
                <ChevronRight aria-hidden="true" />
                All campaigns
            </Link>
            <div class="campaign-side-status">
                <span
                    class="campaign-status-dot"
                    :class="'status-' + campaign.status"
                    aria-hidden="true"
                />
                <span>{{ campaignStatusLabel() }}</span>
            </div>
            <nav class="campaign-section-nav" aria-label="Campaign sections">
                <button
                    type="button"
                    data-test="campaign-tab-overview"
                    :class="{ active: tab === 'overview' }"
                    :aria-current="tab === 'overview' ? 'page' : undefined"
                    @click="setTab('overview')"
                >
                    <span>{{
                        campaign.status === 'draft' ? 'Purpose' : 'Overview'
                    }}</span>
                </button>
                <button
                    type="button"
                    data-test="campaign-tab-audience"
                    :class="{ active: tab === 'audience' }"
                    :aria-current="tab === 'audience' ? 'page' : undefined"
                    @click="setTab('audience')"
                >
                    <span>Audience</span>
                    <small>{{ campaign.people.length }}</small>
                </button>
                <button
                    type="button"
                    data-test="campaign-tab-sequence"
                    :class="{ active: tab === 'sequence' }"
                    :aria-current="tab === 'sequence' ? 'page' : undefined"
                    @click="setTab('sequence')"
                >
                    <span>Sequence</span>
                    <small>{{ campaign.steps.length }}</small>
                </button>
                <button
                    type="button"
                    data-test="campaign-tab-sending"
                    :class="{ active: tab === 'sending' }"
                    :aria-current="tab === 'sending' ? 'page' : undefined"
                    @click="setTab('sending')"
                >
                    <span>Sending</span>
                    <small>{{ campaign.mailboxes.length }}</small>
                </button>
            </nav>
            <Link
                v-if="outcomes.replied > 0"
                class="campaign-replies-link"
                :href="'/unibox?folder=all_replies&campaign=' + campaign.id"
            >
                View campaign replies
                <ChevronRight aria-hidden="true" />
            </Link>
        </template>

        <template #actions>
            <button
                v-if="campaign.status === 'draft' && composition"
                class="btn ghost"
                type="button"
                @click="primarySetupAction"
            >
                {{
                    nextSetupRequirement
                        ? 'Continue setup'
                        : 'Review exact messages'
                }}
            </button>
            <button
                v-if="
                    (campaign.status === 'draft' && !composition) ||
                    campaign.status === 'paused' ||
                    (campaign.status === 'completed' && sendablePeople > 0)
                "
                ref="launchTrigger"
                class="btn"
                type="button"
                @click="
                    composition
                        ? openSetupCheck({ id: 'approval', tab: 'overview' })
                        : openLaunchReview()
                "
            >
                <Play aria-hidden="true" />
                {{
                    campaign.status === 'draft'
                        ? 'Review launch'
                        : campaign.status === 'paused'
                          ? 'Review and resume'
                          : 'Resume campaign'
                }}
            </button>
            <button
                v-else-if="campaign.status === 'active'"
                class="btn ghost"
                type="button"
                :disabled="campaignActionPending"
                @click="pauseCampaign"
            >
                <Pause aria-hidden="true" />
                {{ campaignActionPending ? 'Pausing…' : 'Pause sending' }}
            </button>
            <button
                v-if="
                    campaign.status === 'active' || campaign.status === 'paused'
                "
                class="btn ghost"
                type="button"
                :disabled="campaignActionPending"
                @click="completeCampaign($event)"
            >
                Mark complete
            </button>
        </template>

        <main class="campaign-detail">
            <header class="campaign-detail-head">
                <div>
                    <div class="campaign-title-line">
                        <h1>{{ campaign.name }}</h1>
                        <button
                            ref="renameTrigger"
                            class="campaign-rename"
                            type="button"
                            aria-label="Rename campaign"
                            @click="openRename"
                        >
                            <Pencil aria-hidden="true" />
                        </button>
                        <span
                            class="campaign-status"
                            :class="'status-' + campaign.status"
                            >{{ campaignStatusLabel() }}</span
                        >
                    </div>
                    <p>{{ campaignDescription() }}</p>
                </div>
            </header>

            <section
                v-if="campaign.status === 'draft' && tab !== 'overview'"
                class="campaign-setup-progress"
                aria-label="Setup next step"
            >
                <div>
                    <p class="campaign-eyebrow">
                        Step {{ setupProgress.step }} of 4 · Campaign setup
                    </p>
                    <h2>{{ setupProgress.title }}</h2>
                    <p role="status">{{ setupProgress.detail }}</p>
                </div>
                <button
                    type="button"
                    class="btn"
                    :disabled="emailSavePending || sending.processing"
                    @click="continueSetup"
                >
                    {{ setupProgress.label }}
                </button>
            </section>

            <section v-if="tab === 'overview'" class="campaign-section">
                <p v-if="campaign.status === 'draft'" class="campaign-eyebrow">
                    Step 1 of 4 · Campaign purpose
                </p>
                <div v-if="composition" ref="compositionSection" tabindex="-1">
                    <CampaignCompositionForm
                        ref="compositionForm"
                        section="purpose"
                        :campaign-id="campaign.id"
                        :composition="composition"
                        :setup-feedback="compositionFeedback"
                        @dirty="compositionDirty = $event"
                        @saved="
                            live = '';
                            compositionFeedback = null;
                        "
                        @sending="setTab('sending')"
                    />
                </div>
                <div
                    v-if="outcomes.needs_review > 0"
                    class="campaign-review-banner"
                    role="status"
                >
                    <AlertCircle aria-hidden="true" />
                    <p>
                        {{ outcomes.needs_review }}
                        {{
                            outcomes.needs_review === 1
                                ? 'send needs'
                                : 'sends need'
                        }}
                        manual review. Resolve uncertain sends with
                        <code>php artisan outboss:sends:confirm-sent</code>
                        or
                        <code>confirm-not-sent</code>
                        — see
                        <code>docs/operations.md</code>.
                    </p>
                </div>
                <div class="campaign-summary-strip">
                    <div>
                        <small>Audience</small>
                        <strong>{{ campaign.people.length }}</strong>
                        <span>{{ outcomes.queued }} queued</span>
                    </div>
                    <div>
                        <small>Sequence</small>
                        <strong>{{ campaign.steps.length }}</strong>
                        <span>{{
                            campaign.steps.length === 1 ? 'email' : 'emails'
                        }}</span>
                    </div>
                    <div>
                        <small>Replies</small>
                        <strong>{{ outcomes.replied }}</strong>
                        <span>{{ outcomes.interested }} interested</span>
                    </div>
                    <div>
                        <small>Delivery</small>
                        <strong>{{ outcomes.bounced }}</strong>
                        <span>{{ outcomes.unsubscribed }} unsubscribed</span>
                    </div>
                </div>

                <div class="campaign-overview-grid">
                    <section
                        v-if="campaign.status !== 'completed'"
                        class="campaign-readiness"
                    >
                        <div class="campaign-section-heading">
                            <div>
                                <p class="campaign-eyebrow">Launch readiness</p>
                                <h2>
                                    {{
                                        launchReady
                                            ? 'Ready for review'
                                            : 'Finish campaign setup'
                                    }}
                                </h2>
                                <p>
                                    {{
                                        launchReady
                                            ? 'Audience, message, and sending setup are ready. Review the final send before launch.'
                                            : 'Complete each requirement before the campaign can launch.'
                                    }}
                                </p>
                            </div>
                        </div>
                        <button
                            v-for="check in launchChecks"
                            :key="check.id"
                            type="button"
                            class="campaign-check"
                            @click="openSetupCheck(check)"
                        >
                            <span
                                class="campaign-check-icon"
                                :class="{ ready: check.ready }"
                            >
                                <Check v-if="check.ready" aria-hidden="true" />
                                <AlertCircle v-else aria-hidden="true" />
                            </span>
                            <span>
                                <strong>{{ check.label }}</strong>
                                <small>{{ check.detail }}</small>
                            </span>
                            <ChevronRight aria-hidden="true" />
                        </button>
                    </section>

                    <aside class="campaign-next-step">
                        <p class="campaign-eyebrow">Next step</p>
                        <template v-if="campaign.status === 'active'">
                            <h2>Monitor replies</h2>
                            <p>
                                Sending is active. Work human responses from the
                                Lead Inbox rather than changing the live
                                sequence.
                            </p>
                            <Link
                                class="btn ghost"
                                :href="
                                    '/unibox?folder=all_replies&campaign=' +
                                    campaign.id
                                "
                                >View replies</Link
                            >
                        </template>
                        <template v-else-if="campaign.status === 'completed'">
                            <h2>Review outcomes</h2>
                            <p>
                                Compare replies and interested leads with the
                                original audience.
                            </p>
                            <Link
                                class="btn ghost"
                                :href="
                                    '/unibox?folder=all_replies&campaign=' +
                                    campaign.id
                                "
                                >View replies</Link
                            >
                        </template>
                        <template v-else-if="nextIncompleteCheck">
                            <h2>{{ nextIncompleteCheck.label }}</h2>
                            <p>{{ nextIncompleteCheck.detail }}</p>
                            <button
                                class="btn"
                                type="button"
                                @click="openSetupCheck(nextIncompleteCheck)"
                            >
                                {{ setupActionLabel(nextIncompleteCheck) }}
                            </button>
                        </template>
                        <template v-else>
                            <h2>
                                {{
                                    campaign.status === 'paused'
                                        ? 'Resume safely'
                                        : 'Review launch'
                                }}
                            </h2>
                            <p>
                                Confirm the final audience, sequence, sender
                                identities, and send window.
                            </p>
                            <button
                                class="btn"
                                type="button"
                                @click="openLaunchReview"
                            >
                                {{
                                    campaign.status === 'paused'
                                        ? 'Review and resume'
                                        : 'Review launch'
                                }}
                            </button>
                        </template>
                    </aside>
                </div>

                <div
                    v-if="campaign.has_draft && campaign.status !== 'draft'"
                    class="campaign-version-note"
                    role="status"
                >
                    <AlertCircle aria-hidden="true" />
                    <span>
                        You are editing draft v{{
                            campaign.sequence.draft_version
                        }}. Recipients who already received emails stay on v{{
                            campaign.sequence.published_version
                        }}; {{ unstartedMoveCopy }} to v{{
                            campaign.sequence.draft_version
                        }}
                        when you resume.
                    </span>
                </div>
            </section>

            <section v-else-if="tab === 'audience'" class="campaign-section">
                <div
                    class="campaign-section-heading campaign-section-heading-row"
                >
                    <div>
                        <p class="campaign-eyebrow">Audience</p>
                        <h2>
                            {{ campaign.people.length }}
                            {{ peopleLabel(campaign.people.length) }}
                        </h2>
                        <p>
                            Review who can receive the sequence and who has
                            replied, stopped, or encountered a problem.
                        </p>
                    </div>
                    <button
                        v-if="canAddPeople || campaign.status === 'completed'"
                        ref="addPeopleTrigger"
                        class="btn"
                        type="button"
                        data-test="add-people"
                        @click="openAddPeople"
                    >
                        <Plus aria-hidden="true" />
                        {{
                            campaign.status === 'completed'
                                ? 'Resume to add people'
                                : 'Add people'
                        }}
                    </button>
                </div>
                <p
                    v-if="enrollmentResult"
                    class="campaign-form-help enrollment-summary"
                    role="status"
                >
                    {{ formatEnrollmentSummary(enrollmentResult) }}
                </p>

                <div class="campaign-filter-bar" aria-label="Audience filters">
                    <button
                        v-for="item in audienceFilters"
                        :key="item.id"
                        type="button"
                        :class="{ active: audienceFilter === item.id }"
                        :aria-pressed="audienceFilter === item.id"
                        @click="audienceFilter = item.id"
                    >
                        {{ item.label }}
                        <small>{{ audienceFilterCount(item.id) }}</small>
                    </button>
                </div>

                <div v-if="filteredPeople.length" class="campaign-people-table">
                    <div class="campaign-people-head" aria-hidden="true">
                        <span>Person</span>
                        <span>Company</span>
                        <span>Status</span>
                        <span>Sending from</span>
                        <span />
                    </div>
                    <div
                        v-for="person in filteredPeople"
                        :key="person.id"
                        class="campaign-person-row"
                    >
                        <span class="campaign-person">
                            <strong>
                                <Link
                                    v-if="person.person_id"
                                    class="campaign-person-link"
                                    href="/prospects"
                                    >{{ person.name || person.email }}</Link
                                >
                                <template v-else>{{
                                    person.name || person.email
                                }}</template>
                                <span
                                    v-if="
                                        campaign.sequence.versions_count > 1 &&
                                        person.sequence_version
                                    "
                                    class="sequence-version-tag"
                                    >v{{ person.sequence_version }}</span
                                >
                            </strong>
                            <small>{{ person.email }}</small>
                        </span>
                        <span class="campaign-person-company">
                            {{ person.company || '—' }}
                        </span>
                        <span>
                            <span
                                class="campaign-person-status"
                                :class="
                                    'tone-' + personStatusTone(person.lifecycle)
                                "
                                >{{ personStatusLabel(person.lifecycle) }}</span
                            >
                        </span>
                        <span>
                            <select
                                :value="person.mailbox_id ?? ''"
                                :disabled="
                                    !!person.first_sent_at ||
                                    !audienceControllable
                                "
                                :aria-label="
                                    person.first_sent_at
                                        ? 'Mailbox locked after first send'
                                        : 'Assign mailbox for ' +
                                          (person.name || person.email)
                                "
                                @change="
                                    router.patch(
                                        '/campaign-recipients/' + person.id,
                                        {
                                            mailbox_id: Number(
                                                (
                                                    $event.target as HTMLSelectElement
                                                ).value,
                                            ),
                                        },
                                    )
                                "
                            >
                                <option value="" disabled>Not assigned</option>
                                <option
                                    v-for="box in availableMailboxes"
                                    :key="box.id"
                                    :value="box.id"
                                    :disabled="
                                        box.status !== 'connected' ||
                                        !box.effective_reply_identity
                                    "
                                >
                                    {{ senderLabel(box) }}
                                </option>
                            </select>
                        </span>
                        <span class="campaign-person-actions">
                            <Link
                                v-if="person.thread_id"
                                class="campaign-thread-link"
                                :href="
                                    '/unibox?folder=all_replies&campaign=' +
                                    campaign.id
                                "
                                >View campaign replies</Link
                            >
                            <details
                                v-if="audienceControllable"
                                class="row-menu"
                            >
                                <summary aria-label="More person actions">
                                    <MoreHorizontal aria-hidden="true" />
                                </summary>
                                <div>
                                    <button
                                        v-if="person.lifecycle !== 'paused'"
                                        type="button"
                                        @click="
                                            router.patch(
                                                '/campaign-recipients/' +
                                                    person.id,
                                                { action: 'pause' },
                                            )
                                        "
                                    >
                                        Pause person
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        @click="
                                            router.patch(
                                                '/campaign-recipients/' +
                                                    person.id,
                                                { action: 'resume' },
                                            )
                                        "
                                    >
                                        Resume person
                                    </button>
                                    <button
                                        type="button"
                                        class="danger"
                                        @click="removePerson(person.id, $event)"
                                    >
                                        Remove from campaign
                                    </button>
                                </div>
                            </details>
                        </span>
                    </div>
                </div>
                <div v-else class="campaign-inline-empty">
                    <Users aria-hidden="true" />
                    <h3>
                        {{
                            campaign.people.length
                                ? 'No people match this filter'
                                : 'Add the campaign audience'
                        }}
                    </h3>
                    <p v-if="!campaign.people.length">
                        Select people from your registry, or paste a list to
                        review validation and suppression checks before anyone
                        is enrolled.
                    </p>
                    <button
                        v-if="
                            !campaign.people.length &&
                            (canAddPeople || campaign.status === 'completed')
                        "
                        ref="addPeopleTrigger"
                        class="btn"
                        type="button"
                        data-test="add-people"
                        @click="openAddPeople"
                    >
                        {{
                            campaign.status === 'completed'
                                ? 'Resume to add people'
                                : 'Add people'
                        }}
                    </button>
                </div>
            </section>

            <section v-else-if="tab === 'sequence'" class="campaign-section">
                <div
                    class="campaign-section-heading campaign-section-heading-row"
                >
                    <div>
                        <p class="campaign-eyebrow">Sequence</p>
                        <h2>What each person will receive</h2>
                        <p>
                            Review the email order, timing, subject, and
                            personalization before launch.
                        </p>
                        <p
                            class="sequence-version-line"
                            data-test="sequence-version-line"
                        >
                            {{ sequenceVersionLabel }}
                        </p>
                    </div>
                    <button
                        v-if="!composition"
                        class="btn ghost"
                        type="button"
                        :disabled="!campaign.editable || Boolean(composition)"
                        @click="
                            router.post('/campaigns/' + campaign.id + '/steps')
                        "
                    >
                        <Plus aria-hidden="true" />
                        Add follow-up
                    </button>
                </div>

                <p
                    v-if="
                        (composition &&
                            campaign.steps.some(
                                (step) =>
                                    step.delay_minutes !== 0 ||
                                    step.send_as !== 'new_thread' ||
                                    step.subject_spintax_enabled ||
                                    step.body_spintax_enabled,
                            )) ||
                        (composition && campaign.steps.length > 1)
                    "
                    class="campaign-version-note"
                    role="status"
                >
                    This campaign setup currently supports one immediate,
                    new-thread email without message variations. Follow-ups are
                    not supported yet. Existing steps remain visible; remove
                    extra steps and turn off variations before exact review.
                </p>

                <div
                    v-if="!campaign.editable"
                    class="campaign-version-note"
                    role="status"
                >
                    <AlertCircle aria-hidden="true" />
                    <span>
                        Pause sending before editing. People who already started
                        remain on the published sequence.
                    </span>
                </div>

                <div v-if="currentStep" class="sequence-workspace">
                    <aside class="sequence-steps" aria-label="Sequence emails">
                        <div
                            v-for="(step, index) in campaign.steps"
                            :key="step.id"
                            class="sequence-step-row"
                            :class="{ active: selectedStep === step.id }"
                        >
                            <button
                                type="button"
                                :class="{ active: selectedStep === step.id }"
                                :aria-pressed="selectedStep === step.id"
                                @click="selectedStep = step.id"
                            >
                                <span class="sequence-position">{{
                                    index + 1
                                }}</span>
                                <span>
                                    <strong>{{ step.title }}</strong>
                                    <small>
                                        {{
                                            step.send_as === 'reply'
                                                ? 'Reply'
                                                : 'New thread'
                                        }}
                                        ·
                                        {{
                                            waitOptions.find(
                                                (option) =>
                                                    option.value ===
                                                    step.delay_minutes,
                                            )?.label ??
                                            step.delay_minutes + ' min'
                                        }}
                                    </small>
                                </span>
                            </button>
                            <div
                                v-if="
                                    campaign.editable &&
                                    campaign.steps.length > 1
                                "
                                class="sequence-reorder"
                            >
                                <button
                                    type="button"
                                    aria-label="Move email up"
                                    :disabled="index === 0"
                                    @click="moveStep(index, -1)"
                                >
                                    <ChevronUp aria-hidden="true" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Move email down"
                                    :disabled="
                                        index === campaign.steps.length - 1
                                    "
                                    @click="moveStep(index, 1)"
                                >
                                    <ChevronDown aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                    </aside>

                    <div class="sequence-editor">
                        <div class="sequence-editor-bar">
                            <div class="campaign-segmented">
                                <button
                                    type="button"
                                    :class="{ active: sequenceMode === 'edit' }"
                                    :aria-pressed="sequenceMode === 'edit'"
                                    @click="sequenceMode = 'edit'"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    :class="{
                                        active: sequenceMode === 'preview',
                                    }"
                                    :aria-pressed="sequenceMode === 'preview'"
                                    :disabled="emailDirty || emailSavePending"
                                    @click="sequenceMode = 'preview'"
                                >
                                    Preview
                                </button>
                            </div>
                            <select
                                v-if="
                                    sequenceMode === 'preview' &&
                                    campaign.people.length
                                "
                                v-model="previewPersonId"
                                aria-label="Preview sequence for"
                            >
                                <option
                                    v-for="person in campaign.people"
                                    :key="person.id"
                                    :value="person.id"
                                >
                                    {{ person.name || person.email }}
                                </option>
                            </select>
                        </div>

                        <form
                            v-if="sequenceMode === 'edit'"
                            class="sequence-fields"
                            @submit.prevent="saveEmail()"
                        >
                            <label>
                                <span>Title</span>
                                <input
                                    v-model="emailDraft.title"
                                    :disabled="
                                        !campaign.editable || emailSavePending
                                    "
                                    aria-label="Step title"
                                />
                            </label>
                            <div
                                v-if="
                                    !composition ||
                                    currentStep.delay_minutes !== 0 ||
                                    currentStep.send_as !== 'new_thread'
                                "
                                class="sequence-settings-row"
                            >
                                <label>
                                    <span>Wait</span>
                                    <select
                                        :value="currentStep.delay_minutes"
                                        :disabled="!campaign.editable"
                                        @change="
                                            updateStep(
                                                'delay_minutes',
                                                Number(
                                                    (
                                                        $event.target as HTMLSelectElement
                                                    ).value,
                                                ),
                                            )
                                        "
                                    >
                                        <option
                                            v-for="option in waitOptions"
                                            :key="option.value"
                                            :value="option.value"
                                            :disabled="
                                                Boolean(composition) &&
                                                option.value !== 0
                                            "
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </label>
                                <label>
                                    <span>Send as</span>
                                    <select
                                        :value="currentStep.send_as"
                                        :disabled="!campaign.editable"
                                        @change="
                                            updateStep(
                                                'send_as',
                                                (
                                                    $event.target as HTMLSelectElement
                                                ).value,
                                            )
                                        "
                                    >
                                        <option value="new_thread">
                                            New thread
                                        </option>
                                        <option
                                            value="reply"
                                            :disabled="Boolean(composition)"
                                        >
                                            Reply
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <label>
                                <span>Subject</span>
                                <input
                                    data-test="sequence-subject"
                                    v-model="emailDraft.subject_template"
                                    :disabled="
                                        !campaign.editable ||
                                        emailSavePending ||
                                        currentStep.send_as === 'reply'
                                    "
                                    :aria-readonly="
                                        currentStep.send_as === 'reply'
                                    "
                                />
                                <small v-if="currentStep.send_as === 'reply'">
                                    Reply emails inherit the earlier thread
                                    subject.
                                </small>
                                <small
                                    v-if="pageErrors.subject_template"
                                    class="inline-action-error"
                                    role="alert"
                                    data-test="sequence-subject-error"
                                >
                                    {{ pageErrors.subject_template }}
                                </small>
                            </label>
                            <label>
                                <span>Body</span>
                                <textarea
                                    data-test="sequence-body"
                                    v-model="emailDraft.body_template"
                                    :disabled="
                                        !campaign.editable || emailSavePending
                                    "
                                />
                                <small
                                    v-if="pageErrors.body_template"
                                    class="inline-action-error"
                                    role="alert"
                                    data-test="sequence-body-error"
                                >
                                    {{ pageErrors.body_template }}
                                </small>
                                <small>
                                    Available variables:
                                    <template
                                        v-for="(
                                            variable, index
                                        ) in campaign.supported_variables"
                                        :key="variable"
                                    >
                                        <code>{{ variable }}</code
                                        ><template
                                            v-if="
                                                index <
                                                campaign.supported_variables
                                                    .length -
                                                    1
                                            "
                                        >
                                            and
                                        </template>
                                    </template>
                                    .
                                </small>
                                <small
                                    v-if="!composition"
                                    data-test="sequence-spintax-help"
                                >
                                    Add variations with
                                    <code>{Hi|Hello|Hey}</code>. Outboss keeps
                                    one choice stable for each recipient.
                                </small>
                            </label>
                            <div class="sequence-save-row">
                                <button
                                    v-if="campaign.editable"
                                    type="submit"
                                    class="btn"
                                    :disabled="emailSavePending || !emailDirty"
                                >
                                    {{
                                        emailSavePending
                                            ? 'Saving email…'
                                            : 'Save email'
                                    }}
                                </button>
                                <p
                                    v-if="emailSaveError"
                                    class="inline-action-error"
                                    role="alert"
                                >
                                    {{ emailSaveError }}
                                </p>
                                <p v-else-if="emailDirty" role="status">
                                    Unsaved email changes. Save before
                                    previewing, sending a test, or continuing.
                                </p>
                                <p v-else-if="emailSaveStatus" role="status">
                                    {{ emailSaveStatus }}
                                </p>
                            </div>
                            <div
                                v-if="
                                    !composition ||
                                    currentStep.subject_spintax_enabled ||
                                    currentStep.body_spintax_enabled
                                "
                                class="sequence-test-row"
                            >
                                <label
                                    ><input
                                        type="checkbox"
                                        :checked="
                                            currentStep.subject_spintax_enabled
                                        "
                                        :disabled="
                                            !campaign.editable ||
                                            Boolean(
                                                composition &&
                                                !currentStep.subject_spintax_enabled,
                                            )
                                        "
                                        @change="
                                            updateStep(
                                                'subject_spintax_enabled',
                                                (
                                                    $event.target as HTMLInputElement
                                                ).checked,
                                            )
                                        "
                                    />Subject variations</label
                                >
                                <label
                                    ><input
                                        type="checkbox"
                                        :checked="
                                            currentStep.body_spintax_enabled
                                        "
                                        :disabled="
                                            !campaign.editable ||
                                            Boolean(
                                                composition &&
                                                !currentStep.body_spintax_enabled,
                                            )
                                        "
                                        @change="
                                            updateStep(
                                                'body_spintax_enabled',
                                                (
                                                    $event.target as HTMLInputElement
                                                ).checked,
                                            )
                                        "
                                    />Body variations</label
                                >
                                <small
                                    >Turn both off for exact campaign review.
                                    Personalization variables still work.</small
                                >
                            </div>
                            <div class="sequence-test-row">
                                <div>
                                    <strong>Send a test</strong>
                                    <small>
                                        Test this email through a connected
                                        sender.
                                    </small>
                                </div>
                                <input
                                    v-model="testTo"
                                    type="email"
                                    placeholder="you@example.com"
                                    aria-label="Test address"
                                    :aria-invalid="Boolean(testSendError)"
                                    :aria-describedby="
                                        testSendError
                                            ? 'test-send-error'
                                            : undefined
                                    "
                                />
                                <select
                                    v-model="testMailbox"
                                    aria-label="Test mailbox"
                                >
                                    <option
                                        v-for="box in availableMailboxes"
                                        :key="box.id"
                                        :value="box.id"
                                        :disabled="
                                            box.status !== 'connected' ||
                                            !box.effective_reply_identity
                                        "
                                    >
                                        {{ senderLabel(box) }}
                                    </option>
                                </select>
                                <button
                                    class="btn ghost"
                                    type="button"
                                    :disabled="
                                        !testTo ||
                                        !testMailboxReady ||
                                        testSendPending
                                    "
                                    @click="sendTest"
                                >
                                    {{
                                        testSendPending
                                            ? 'Sending test…'
                                            : 'Send test'
                                    }}
                                </button>
                            </div>
                            <p
                                v-if="testSendError"
                                id="test-send-error"
                                role="alert"
                                class="test-send-error"
                            >
                                {{ testSendError }}
                            </p>
                            <div class="sequence-danger-row">
                                <button
                                    class="campaign-text-danger"
                                    type="button"
                                    :disabled="!campaign.editable"
                                    @click="deleteCurrentStep($event)"
                                >
                                    Delete this email
                                </button>
                            </div>
                        </form>

                        <SequenceStepPreview
                            v-else
                            :step-id="currentStep.id"
                            :saved-content-key="JSON.stringify(currentStep)"
                            :recipient-id="previewPersonId"
                            :people="campaign.people"
                        />
                    </div>
                </div>
                <div v-else class="campaign-inline-empty">
                    <Mail aria-hidden="true" />
                    <h3>Add the first email</h3>
                    <p>
                        A campaign needs an immediate new-thread email before it
                        can launch.
                    </p>
                </div>
            </section>

            <section v-else class="campaign-section">
                <div class="campaign-section-heading">
                    <p class="campaign-eyebrow">Sending</p>
                    <h2>Sender identities and schedule</h2>
                    <p>
                        Choose who sends this campaign and confirm when messages
                        are allowed to leave.
                    </p>
                </div>

                <div class="sending-layout">
                    <section class="sending-setup">
                        <section
                            class="sending-identities-card"
                            aria-label="Sender identities"
                            data-test="sending-identities"
                        >
                            <p class="campaign-eyebrow">Sender identities</p>
                            <h3>Who will send this campaign?</h3>
                            <p
                                v-if="availableMailboxes.length === 0"
                                class="sending-identities-empty"
                            >
                                No inboxes are connected yet.
                                <Link
                                    class="campaign-settings-link"
                                    :href="`/settings/mailboxes?from_campaign=${campaign.id}`"
                                    @click.prevent="openMailboxSettings"
                                >
                                    Connect an inbox
                                </Link>
                            </p>
                            <template v-else>
                                <label for="send-mode"
                                    >Mailbox assignment</label
                                >
                                <select
                                    id="send-mode"
                                    v-model="sending.send_mode"
                                    :disabled="!campaign.editable"
                                >
                                    <option value="rotate">
                                        Rotate evenly across selected inboxes
                                    </option>
                                    <option value="pin">
                                        Pin everyone to one inbox
                                    </option>
                                </select>

                                <div class="sending-mailboxes">
                                    <label
                                        v-for="box in availableMailboxes"
                                        :key="box.id"
                                        :class="{
                                            unavailable:
                                                box.status !== 'connected' ||
                                                !box.effective_reply_identity ||
                                                box.sending_paused,
                                        }"
                                    >
                                        <input
                                            v-model="sending.mailbox_ids"
                                            type="checkbox"
                                            :value="box.id"
                                            :disabled="
                                                !campaign.editable ||
                                                ((box.status !== 'connected' ||
                                                    !box.effective_reply_identity ||
                                                    box.sending_paused) &&
                                                    !sending.mailbox_ids.includes(
                                                        box.id,
                                                    ))
                                            "
                                        />
                                        <span class="sending-mailbox-copy">
                                            <strong>{{
                                                senderLabel(box)
                                            }}</strong>
                                            <small>{{
                                                box.email_address
                                            }}</small>
                                        </span>
                                        <span
                                            class="sending-mailbox-status"
                                            :class="{
                                                ready: isReadyMailbox(box),
                                            }"
                                        >
                                            {{ mailboxSendingStatus(box) }}
                                        </span>
                                    </label>
                                </div>
                            </template>
                        </section>

                        <section
                            class="sending-capacity-card"
                            aria-label="Daily sending capacity"
                        >
                            <p class="campaign-eyebrow">Daily pace</p>
                            <h3>How many messages per day?</h3>
                            <label for="daily-send-limit"
                                >Messages per day</label
                            >
                            <input
                                id="daily-send-limit"
                                v-model.number="sending.daily_send_limit"
                                type="number"
                                min="1"
                                max="1000000"
                                placeholder="Auto from selected inboxes"
                                :disabled="!campaign.editable"
                            />
                            <p class="sending-capacity-summary">
                                {{ selectedReadyMailboxes.length }} ready
                                {{
                                    selectedReadyMailboxes.length === 1
                                        ? 'inbox'
                                        : 'inboxes'
                                }}
                                · {{ selectedInboxDailyCapacity }}/day ·
                                {{ selectedInboxRemainingToday }} available
                                today<span
                                    v-if="
                                        !sendingNeedsSave &&
                                        composition?.sending_forecast
                                            ?.estimated_finish_at
                                    "
                                >
                                    · Estimated finish
                                    {{
                                        composition.sending_forecast.estimated_finish_at.slice(
                                            0,
                                            10,
                                        )
                                    }}</span
                                >
                            </p>
                            <p
                                v-if="dailyLimitShortfall > 0"
                                class="sending-capacity-warning"
                                role="alert"
                            >
                                The target is
                                {{ dailyLimitShortfall }} messages/day above the
                                selected inbox capacity. Add ready inboxes,
                                increase an inbox cap, or lower the target.
                            </p>
                            <p
                                v-if="
                                    !sendingNeedsSave &&
                                    dailyLimitShortfall === 0 &&
                                    composition?.sending_forecast &&
                                    [
                                        'daily_limit_exceeded',
                                        'over_capacity',
                                        'allowance_exceeded',
                                        'unavailable',
                                    ].includes(
                                        composition.sending_forecast.status,
                                    )
                                "
                                class="sending-capacity-warning"
                                role="alert"
                            >
                                {{ composition.sending_forecast.message }}
                            </p>
                            <div class="sending-capacity-actions">
                                <button
                                    v-if="
                                        unselectedReadyMailboxes.length &&
                                        (dailyLimitShortfall > 0 ||
                                            selectedReadyMailboxes.length === 0)
                                    "
                                    class="btn ghost"
                                    type="button"
                                    :disabled="!campaign.editable"
                                    @click="useReadyInboxes"
                                >
                                    Use {{ unselectedReadyMailboxes.length }}
                                    ready
                                    {{
                                        unselectedReadyMailboxes.length === 1
                                            ? 'inbox'
                                            : 'inboxes'
                                    }}
                                </button>
                                <button
                                    v-if="
                                        dailyLimitShortfall > 0 &&
                                        selectedInboxDailyCapacity > 0
                                    "
                                    class="btn ghost"
                                    type="button"
                                    :disabled="!campaign.editable"
                                    @click="lowerDailyTargetToCapacity"
                                >
                                    Lower target to
                                    {{ selectedInboxDailyCapacity }}/day
                                </button>
                                <Link
                                    v-if="
                                        dailyLimitShortfall > 0 ||
                                        composition?.sending_forecast
                                            ?.status === 'daily_limit_exceeded'
                                    "
                                    class="campaign-settings-link"
                                    :href="`/settings/mailboxes?from_campaign=${campaign.id}`"
                                    @click.prevent="openMailboxSettings"
                                >
                                    Connect inboxes or increase inbox caps
                                </Link>
                            </div>
                        </section>

                        <details class="sending-advanced">
                            <summary>Advanced schedule and tracking</summary>
                            <label class="sending-tracking">
                                <input
                                    v-model="sending.open_tracking_enabled"
                                    type="checkbox"
                                    :disabled="!campaign.editable"
                                    data-test="open-tracking-toggle"
                                />
                                <span>
                                    <strong>Open tracking</strong>
                                    <small>
                                        Adds an invisible image to detect opens.
                                        Some mail clients block or falsify this.
                                        Off by default.
                                    </small>
                                </span>
                            </label>

                            <fieldset class="sending-window">
                                <legend>Send window</legend>
                                <div class="sending-window-times">
                                    <label>
                                        <span>Start</span>
                                        <input
                                            v-model="sending.send_window_start"
                                            type="time"
                                            :disabled="!campaign.editable"
                                            data-test="send-window-start"
                                        />
                                    </label>
                                    <label>
                                        <span>End</span>
                                        <input
                                            v-model="sending.send_window_end"
                                            type="time"
                                            :disabled="!campaign.editable"
                                            data-test="send-window-end"
                                        />
                                    </label>
                                </div>
                                <label class="sending-tracking">
                                    <input
                                        v-model="sending.business_days_only"
                                        type="checkbox"
                                        :disabled="!campaign.editable"
                                        data-test="business-days-only"
                                    />
                                    <span>
                                        <strong>Weekdays only</strong>
                                        <small>
                                            Turn this off to allow sends on
                                            Saturdays and Sundays.
                                        </small>
                                    </span>
                                </label>
                                <p
                                    v-if="
                                        sending.errors.send_window_start ||
                                        sending.errors.send_window_end
                                    "
                                    class="inline-action-error"
                                    role="alert"
                                >
                                    {{
                                        sending.errors.send_window_start ||
                                        sending.errors.send_window_end
                                    }}
                                </p>
                            </fieldset>
                        </details>

                        <p
                            v-for="(error, key) in sending.errors"
                            :key="key"
                            role="alert"
                        >
                            {{ error }}
                        </p>
                        <div class="sending-actions">
                            <button
                                class="btn"
                                type="button"
                                :disabled="
                                    !campaign.editable ||
                                    sending.processing ||
                                    (campaign.status !== 'draft' &&
                                        !sendingNeedsSave)
                                "
                                @click="
                                    sendingNeedsSave
                                        ? saveSending(
                                              campaign.status === 'draft',
                                          )
                                        : primarySetupAction()
                                "
                            >
                                {{
                                    sending.processing
                                        ? 'Saving…'
                                        : campaign.status === 'draft'
                                          ? sendingNeedsSave
                                              ? 'Save and continue'
                                              : 'Review exact messages'
                                          : 'Save sending'
                                }}
                            </button>
                        </div>
                    </section>

                    <details class="sending-schedule">
                        <summary>
                            Send window {{ sending.send_window_start }}–{{
                                sending.send_window_end
                            }}
                            ·
                            {{
                                sending.business_days_only
                                    ? 'Weekdays'
                                    : 'Every day'
                            }}
                        </summary>
                        <div>
                            <Check aria-hidden="true" />
                            <span
                                >Human replies stop later sequence emails.</span
                            >
                        </div>
                        <div>
                            <Check aria-hidden="true" />
                            <span>
                                Suppressed and invalid people are excluded
                                during import.
                            </span>
                        </div>
                        <p class="campaign-eyebrow">Domain authentication</p>
                        <h3>SPF, DKIM, and DMARC</h3>
                        <p>
                            Ask your DNS host to publish these records for the
                            mailbox domain before you send at volume. Missing
                            records do not block launch.
                        </p>
                        <div>
                            <Check aria-hidden="true" />
                            <span>
                                SPF: a TXT record starting
                                <code>v=spf1</code> on the sending domain.
                            </span>
                        </div>
                        <div>
                            <Check aria-hidden="true" />
                            <span>
                                DKIM: a TXT record at
                                <code>selector._domainkey</code> from your
                                mailbox provider.
                            </span>
                        </div>
                        <div>
                            <Check aria-hidden="true" />
                            <span>
                                DMARC: a TXT record at
                                <code>_dmarc.domain</code> starting
                                <code>v=DMARC1</code>.
                            </span>
                        </div>
                        <small>
                            Daily send caps live on each mailbox. Spacing and
                            per-domain limits use the workspace configuration
                            and reset on the UTC day boundary.
                        </small>
                    </details>
                </div>
                <details
                    v-if="composition"
                    class="sending-advanced sending-permission"
                >
                    <summary>Advanced timing and sending permission</summary>
                    <div ref="compositionSection" tabindex="-1">
                        <CampaignCompositionForm
                            ref="compositionForm"
                            section="sending"
                            :campaign-id="campaign.id"
                            :composition="composition"
                            :setup-feedback="compositionFeedback"
                            @dirty="compositionDirty = $event"
                            @saved="
                                live = '';
                                compositionFeedback = null;
                            "
                            @sending="setTab('sending')"
                        />
                    </div>
                </details>
            </section>
        </main>
    </AppWorkspace>

    <div v-if="showAddPeople" class="modal-back">
        <div
            ref="addPeopleDialog"
            class="modal campaign-modal"
            :class="{ 'campaign-modal-wide': addPeopleMode === 'registry' }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="add-people-title"
            tabindex="-1"
        >
            <div class="campaign-modal-head">
                <div>
                    <p class="campaign-eyebrow">Audience</p>
                    <h2 id="add-people-title">Add people</h2>
                </div>
                <button
                    type="button"
                    aria-label="Close add people"
                    @click="closeAddPeople"
                >
                    <X aria-hidden="true" />
                </button>
            </div>
            <div
                class="campaign-segmented add-people-modes"
                role="tablist"
                aria-label="Add people source"
            >
                <button
                    type="button"
                    role="tab"
                    :class="{ active: addPeopleMode === 'registry' }"
                    :aria-selected="addPeopleMode === 'registry'"
                    @click="setAddPeopleMode('registry')"
                >
                    From registry
                </button>
                <button
                    type="button"
                    role="tab"
                    :class="{ active: addPeopleMode === 'paste' }"
                    :aria-selected="addPeopleMode === 'paste'"
                    @click="setAddPeopleMode('paste')"
                >
                    Paste / CSV
                </button>
            </div>
            <CampaignEnrollmentDialog
                v-if="addPeopleMode === 'registry'"
                ref="enrollmentDialog"
                :campaign-id="campaign.id"
                @enrolled="handleEnrolled"
            />
            <div
                v-if="addPeopleMode === 'registry'"
                class="campaign-import-shortcut"
            >
                <span>Or add a list from your computer</span>
                <label class="btn ghost" for="campaign-import-shortcut-file">
                    Upload CSV
                    <input
                        id="campaign-import-shortcut-file"
                        type="file"
                        accept=".csv,text/csv"
                        @change="onImportFile"
                    />
                </label>
            </div>
            <template v-else-if="importStep === 'source'">
                <label for="paste-people">Paste CSV data</label>
                <textarea
                    id="paste-people"
                    ref="pasteInput"
                    v-model="paste"
                    placeholder="email,name,company&#10;maya@stripe.com,Maya Chen,Stripe"
                />
                <label class="campaign-import-file" for="campaign-import-file">
                    Or upload a CSV file
                    <input
                        id="campaign-import-file"
                        type="file"
                        accept=".csv,text/csv"
                        @change="onImportFile"
                    />
                </label>
                <p class="campaign-form-help">
                    Headers are detected automatically. You will choose which
                    columns to import before any contacts are added.
                </p>
                <p
                    v-if="importParseError"
                    class="campaign-form-error"
                    role="alert"
                >
                    {{ importParseError }}
                </p>
                <div class="campaign-dialog-actions">
                    <button
                        class="btn ghost"
                        type="button"
                        @click="samplePeople"
                    >
                        Use sample data
                    </button>
                    <button
                        class="btn"
                        type="button"
                        :disabled="!paste.trim()"
                        @click="continueImport"
                    >
                        Continue
                    </button>
                </div>
            </template>
            <template v-else>
                <p class="campaign-form-help">
                    {{ importRows.length }} rows found<span
                        v-if="importFilename"
                    >
                        in {{ importFilename }}</span
                    >. Email is required. Optional fields become variables in
                    the sequence.
                </p>
                <label
                    v-for="field in [
                        ['email', 'Email address', true],
                        ['name', 'Full name', false],
                        ['first_name', 'First name ({{first_name}})', false],
                        ['company', 'Company ({{company}})', false],
                    ] as const"
                    :key="field[0]"
                    :for="`campaign-import-map-${field[0]}`"
                >
                    {{ field[1] }}
                    <select
                        :id="`campaign-import-map-${field[0]}`"
                        v-model="importMapping[field[0]]"
                    >
                        <option value="">
                            {{ field[2] ? 'Choose a column' : 'Not mapped' }}
                        </option>
                        <option
                            v-for="header in importHeaders"
                            :key="header"
                            :value="header"
                        >
                            {{ header }}
                        </option>
                    </select>
                </label>
                <p
                    v-if="importValidationError"
                    class="campaign-form-error"
                    role="alert"
                >
                    {{ importValidationError }}
                </p>
                <p v-if="importError" role="alert">{{ importError }}</p>
                <div class="campaign-dialog-actions">
                    <button
                        class="btn ghost"
                        type="button"
                        @click="importStep = 'source'"
                    >
                        Back
                    </button>
                    <button
                        class="btn"
                        type="button"
                        :disabled="!importMapping.email || importPending"
                        @click="previewPeople"
                    >
                        {{ importPending ? 'Reviewing…' : 'Review import' }}
                    </button>
                </div>
            </template>
        </div>
    </div>

    <div v-if="showImport" class="modal-back">
        <div
            ref="importDialog"
            class="modal campaign-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="import-title"
            tabindex="-1"
        >
            <div class="campaign-modal-head">
                <div>
                    <p class="campaign-eyebrow">Validation</p>
                    <h2 id="import-title">Review import</h2>
                </div>
                <button
                    type="button"
                    aria-label="Close import review"
                    @click="closeImportReview"
                >
                    <X aria-hidden="true" />
                </button>
            </div>
            <div class="import-results">
                <div class="ready">
                    <strong>{{ importReview?.ready ?? 0 }}</strong>
                    <span>Ready</span>
                </div>
                <div>
                    <strong>{{ importReview?.invalid ?? 0 }}</strong>
                    <span>Invalid</span>
                </div>
                <div>
                    <strong>{{ importReview?.duplicate ?? 0 }}</strong>
                    <span>Duplicate</span>
                </div>
                <div>
                    <strong>{{ importReview?.suppressed ?? 0 }}</strong>
                    <span>Suppressed</span>
                </div>
            </div>
            <p v-if="importError" class="campaign-form-error" role="alert">
                {{ importError }}
            </p>
            <CampaignImportRows
                v-if="importReview?.id"
                :campaign-id="campaign.id"
                :import-id="importReview.id"
            />
            <p v-if="importReview?.suppressed" class="campaign-form-help">
                Suppressed people are saved to your registry but will not be
                enrolled.
                <Link href="/settings/suppressions">Manage suppressions</Link>
            </p>
            <div class="campaign-dialog-actions">
                <button
                    class="btn ghost"
                    type="button"
                    @click="returnToAddPeople"
                >
                    Back
                </button>
                <button
                    class="btn"
                    type="button"
                    :disabled="
                        importPending ||
                        !(importReview?.ready && importReview.ready > 0)
                    "
                    @click="commitImport"
                >
                    {{ importPending ? 'Adding…' : 'Add ready people' }}
                </button>
            </div>
        </div>
    </div>

    <div v-if="showLaunch" class="modal-back">
        <div
            ref="launchDialog"
            class="modal campaign-modal launch-review"
            role="dialog"
            aria-modal="true"
            aria-labelledby="launch-title"
            tabindex="-1"
        >
            <div class="campaign-modal-head">
                <div>
                    <p class="campaign-eyebrow">Final review</p>
                    <h2 id="launch-title">
                        {{
                            campaign.status === 'draft'
                                ? 'Launch campaign'
                                : 'Resume campaign'
                        }}
                    </h2>
                </div>
                <button
                    type="button"
                    aria-label="Close launch review"
                    :disabled="campaignActionPending"
                    @click="closeLaunchReview"
                >
                    <X aria-hidden="true" />
                </button>
            </div>
            <p class="launch-review-intro">
                Confirm the audience, sequence, and sender setup. The server
                runs the same checks again before any sending begins.
            </p>
            <div class="launch-checks">
                <button
                    v-for="check in launchChecks"
                    :key="check.id"
                    type="button"
                    @click="reviewLaunchCheck(check.tab)"
                >
                    <span
                        class="campaign-check-icon"
                        :class="{ ready: check.ready }"
                    >
                        <Check v-if="check.ready" aria-hidden="true" />
                        <AlertCircle v-else aria-hidden="true" />
                    </span>
                    <span>
                        <strong>{{ check.label }}</strong>
                        <small>{{ check.detail }}</small>
                    </span>
                    <ChevronRight aria-hidden="true" />
                </button>
            </div>
            <div class="launch-schedule">
                <Send aria-hidden="true" />
                <span>
                    <strong>
                        {{
                            campaign.mailbox_emails.join(', ') ||
                            'No sender selected'
                        }}
                    </strong>
                    <small>
                        {{
                            campaign.business_days_only
                                ? 'Weekdays'
                                : 'Every day'
                        }}
                        {{ campaign.send_window_start.slice(0, 5) }}–{{
                            campaign.send_window_end.slice(0, 5)
                        }}
                        {{ campaign.timezone }}
                    </small>
                </span>
            </div>
            <p class="launch-safety-note">
                Human replies, hard bounces, and opt-outs stop later sequence
                emails for that person.
            </p>
            <p
                v-if="Object.keys(pageErrors).length"
                class="inline-action-error"
                role="alert"
            >
                {{ Object.values(pageErrors)[0] }}
            </p>
            <div class="campaign-dialog-actions">
                <button
                    class="btn ghost"
                    type="button"
                    :disabled="campaignActionPending"
                    @click="closeLaunchReview"
                >
                    Cancel
                </button>
                <button
                    class="btn"
                    type="button"
                    :disabled="!launchReady || campaignActionPending"
                    @click="launchCampaign"
                >
                    {{
                        campaignActionPending
                            ? campaign.status === 'draft'
                                ? 'Launching…'
                                : 'Resuming…'
                            : campaign.status === 'draft'
                              ? `Launch to ${sendablePeople} ${sendablePeople === 1 ? 'person' : 'people'}`
                              : 'Resume campaign'
                    }}
                </button>
            </div>
        </div>
    </div>

    <div v-if="showRename" class="modal-back">
        <form
            ref="renameDialog"
            class="modal campaign-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="rename-title"
            tabindex="-1"
            @submit.prevent="submitRename"
        >
            <div class="campaign-modal-head">
                <div>
                    <p class="campaign-eyebrow">Campaign name</p>
                    <h2 id="rename-title">Rename campaign</h2>
                </div>
                <button
                    type="button"
                    aria-label="Close rename"
                    :disabled="renameForm.processing"
                    @click="closeRename"
                >
                    <X aria-hidden="true" />
                </button>
            </div>
            <label class="campaign-field">
                <span>Name</span>
                <input
                    ref="renameInput"
                    v-model="renameForm.name"
                    type="text"
                    maxlength="120"
                    required
                    :disabled="renameForm.processing"
                />
                <small
                    v-if="renameForm.errors.name"
                    class="inline-action-error"
                >
                    {{ renameForm.errors.name }}
                </small>
            </label>
            <div class="campaign-dialog-actions">
                <button
                    class="btn ghost"
                    type="button"
                    :disabled="renameForm.processing"
                    @click="closeRename"
                >
                    Cancel
                </button>
                <button
                    class="btn"
                    type="submit"
                    :disabled="renameForm.processing || !renameForm.name.trim()"
                >
                    {{ renameForm.processing ? 'Saving…' : 'Save name' }}
                </button>
            </div>
        </form>
    </div>

    <div
        v-if="confirmDialog"
        class="modal-back"
        @click.self="closeConfirmDialog"
    >
        <div
            ref="confirmDialogEl"
            class="modal campaign-modal campaign-confirm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="campaign-confirm-title"
            tabindex="-1"
        >
            <button
                class="campaign-confirm-close"
                type="button"
                aria-label="Close confirmation"
                @click="closeConfirmDialog"
            >
                <X aria-hidden="true" />
            </button>
            <p class="campaign-eyebrow">Confirm</p>
            <h2 id="campaign-confirm-title">{{ confirmDialog.title }}</h2>
            <p class="campaign-confirm-body">{{ confirmDialog.body }}</p>
            <div class="campaign-dialog-actions">
                <button
                    class="btn ghost"
                    type="button"
                    @click="closeConfirmDialog"
                >
                    Cancel
                </button>
                <button
                    class="btn"
                    :class="{ danger: confirmDialog.danger }"
                    type="button"
                    data-test="campaign-confirm-accept"
                    @click="acceptConfirmDialog"
                >
                    {{ confirmDialog.confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.campaign-setup-progress {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid var(--border, #dededb);
    border-radius: 12px;
    background: var(--surface, #fff);
}
.campaign-setup-progress p {
    margin: 6px 0;
}
.campaign-setup-progress h2 {
    font-size: 16px;
    font-weight: 600;
}
.campaign-feedback {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 80;
    max-width: min(480px, calc(100vw - 40px));
    border: 1px solid #c7bfae;
    border-radius: 8px;
    padding: 12px 16px;
    background: #fff;
    color: #29251f;
    box-shadow: 0 4px 18px rgb(31 28 24 / 12%);
}
.test-send-error {
    color: #a12b25;
    font-size: 13px;
}
.campaign-back {
    display: flex;
    min-height: 40px;
    align-items: center;
    gap: 6px;
    margin: 0 12px 12px;
    border-radius: 8px;
    padding: 0 9px;
    color: #575149;
    font-size: 12px;
    text-decoration: none;
}
.campaign-back:hover {
    background: #f3f0ea;
}
.campaign-back svg {
    width: 14px;
    height: 14px;
    transform: rotate(180deg);
}
.campaign-side-status {
    display: flex;
    min-height: 36px;
    align-items: center;
    gap: 8px;
    margin: 0 20px 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e7e1d8;
    color: #746d64;
    font-size: 11px;
}
.campaign-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #aaa39a;
}
.campaign-status-dot.status-active {
    background: #21a474;
}
.campaign-status-dot.status-paused {
    background: #d69528;
}
.campaign-status-dot.status-draft {
    background: var(--ob-focus);
}
.campaign-status-dot.status-completed {
    background: #4a6fa5;
}
.campaign-section-nav {
    display: grid;
    gap: 2px;
    padding: 0 12px;
}
.campaign-section-nav button {
    display: flex;
    min-height: 41px;
    align-items: center;
    justify-content: space-between;
    border: 0;
    border-radius: 8px;
    padding: 0 10px;
    background: transparent;
    color: #4d4841;
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.campaign-section-nav button:hover,
.campaign-section-nav button.active {
    background: var(--ob-accent-tint);
    color: var(--ob-accent);
}
.campaign-section-nav small {
    color: #8c857c;
    font-size: 11px;
}
.campaign-replies-link {
    display: flex;
    min-height: 40px;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin: 18px 20px 0;
    padding-top: 14px;
    border-top: 1px solid #e7e1d8;
    color: var(--ob-accent-strong);
    font-size: 11px;
    font-weight: 620;
    text-decoration: none;
}
.campaign-replies-link svg {
    width: 14px;
    height: 14px;
}
:deep(.top .btn svg) {
    width: 15px;
    height: 15px;
}
.campaign-detail {
    min-width: 0;
    padding: 34px 40px 64px;
}
.campaign-detail-head {
    padding-bottom: 26px;
    border-bottom: 1px solid #e3ddd4;
}
.campaign-title-line {
    display: flex;
    align-items: center;
    gap: 10px;
}
.campaign-title-line h1 {
    margin: 0;
    color: #1f1c18;
    font-size: 27px;
    font-weight: 650;
    letter-spacing: -0.03em;
}
.campaign-status {
    border-radius: 999px;
    padding: 4px 8px;
    background: #efede9;
    color: #665f56;
    font-size: 9px;
    font-weight: 680;
}
.campaign-status.status-active {
    background: #e4f7ef;
    color: #167a57;
}
.campaign-status.status-draft {
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
}
.campaign-status.status-paused {
    background: #fff2db;
    color: #925f0d;
}
.campaign-status.status-completed {
    background: #e8eef7;
    color: #35527a;
}
.campaign-rename {
    display: inline-flex;
    width: 28px;
    height: 28px;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #8a8379;
    cursor: pointer;
}
.campaign-rename:hover,
.campaign-rename:focus-visible {
    background: #f1ede7;
    color: #3f3a34;
}
.campaign-rename svg {
    width: 14px;
    height: 14px;
}
.campaign-field {
    display: grid;
    gap: 8px;
    margin: 18px 0 0;
}
.campaign-field > span {
    color: #5f584f;
    font-size: 12px;
    font-weight: 650;
}
.campaign-field input {
    min-height: 42px;
    border: 1px solid #d9d2c8;
    border-radius: 10px;
    padding: 0 12px;
    background: #fff;
    color: #241f1a;
    font: inherit;
}
.campaign-detail-head p,
.campaign-section-heading p,
.campaign-next-step p {
    max-width: 660px;
    margin: 8px 0 0;
    color: #746d64;
    font-size: 13px;
    line-height: 1.55;
}
.campaign-section {
    padding-top: 30px;
}
.campaign-eyebrow {
    margin: 0 0 7px !important;
    color: var(--ob-focus) !important;
    font-size: 10px !important;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.campaign-section-heading h2,
.campaign-next-step h2,
.campaign-readiness h2 {
    margin: 0;
    color: #25211c;
    font-size: 19px;
    font-weight: 650;
    letter-spacing: -0.02em;
}
.campaign-section-heading-row {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 28px;
}
.campaign-section-heading-row .btn svg {
    width: 15px;
    height: 15px;
}
.campaign-review-banner {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 16px;
    border: 1px solid #f0d7a4;
    border-radius: 10px;
    background: #fff8ea;
    padding: 12px 14px;
    color: #6d4f12;
}
.campaign-review-banner svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin-top: 2px;
}
.campaign-review-banner p {
    margin: 0;
    font-size: 13px;
    line-height: 1.45;
}
.campaign-review-banner code {
    font-size: 12px;
}
.campaign-summary-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-bottom: 1px solid #e3ddd4;
}
.campaign-summary-strip > div {
    display: grid;
    min-width: 0;
    gap: 3px;
    padding: 0 22px 24px 0;
}
.campaign-summary-strip > div + div {
    padding-left: 22px;
    border-left: 1px solid #e8e2da;
}
.campaign-summary-strip small {
    color: #8b847a;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.campaign-summary-strip strong {
    color: #25211c;
    font-size: 24px;
    font-weight: 630;
}
.campaign-summary-strip span {
    overflow: hidden;
    color: #746d64;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.campaign-overview-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(260px, 0.75fr);
    gap: 40px;
    padding-top: 32px;
}
.campaign-readiness {
    min-width: 0;
}
.campaign-check {
    display: grid;
    width: 100%;
    min-height: 70px;
    grid-template-columns: 30px minmax(0, 1fr) 18px;
    align-items: center;
    gap: 12px;
    border: 0;
    border-bottom: 1px solid #e8e2da;
    padding: 10px 4px 10px 0;
    background: transparent;
    color: #302c26;
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.campaign-check:hover {
    background: #faf8f4;
}
.campaign-check > span:nth-child(2) {
    display: grid;
    gap: 4px;
}
.campaign-check strong {
    font-size: 13px;
    font-weight: 640;
}
.campaign-check small {
    color: #746d64;
    font-size: 11px;
}
.campaign-check > svg {
    width: 15px;
    color: #918a80;
}
.campaign-check-icon {
    display: grid;
    width: 26px;
    height: 26px;
    place-items: center;
    border-radius: 50%;
    background: #fff0ea;
    color: #b14b35;
}
.campaign-check-icon.ready {
    background: #e4f7ef;
    color: #167a57;
}
.campaign-check-icon svg {
    width: 14px;
    height: 14px;
}
.campaign-next-step {
    align-self: start;
    border-left: 1px solid #e3ddd4;
    padding: 4px 0 8px 28px;
}
.campaign-next-step .btn {
    margin-top: 20px;
}
.campaign-version-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 28px;
    border: 1px solid var(--ob-accent-tint-strong);
    border-radius: 9px;
    padding: 13px 14px;
    background: var(--ob-accent-tint-soft);
    color: var(--ob-accent);
    font-size: 11px;
    line-height: 1.5;
}
.campaign-version-note svg {
    width: 16px;
    height: 16px;
    flex: 0 0 16px;
    margin-top: 1px;
}
.campaign-filter-bar {
    display: flex;
    gap: 4px;
    margin-top: 26px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e3ddd4;
    overflow-x: auto;
}
.campaign-filter-bar button {
    display: flex;
    min-height: 38px;
    align-items: center;
    gap: 7px;
    border: 0;
    border-radius: 7px;
    padding: 0 11px;
    background: transparent;
    color: #635d55;
    font: inherit;
    font-size: 11px;
    cursor: pointer;
}
.campaign-filter-bar button:hover,
.campaign-filter-bar button.active {
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
}
.campaign-filter-bar small {
    color: #8b847a;
    font-size: 10px;
}
.campaign-people-head,
.campaign-person-row {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.3fr) minmax(110px, 0.75fr) minmax(100px, 0.65fr)
        minmax(220px, 1.4fr) 44px;
    align-items: center;
    gap: 16px;
}
.campaign-people-head {
    min-height: 42px;
    color: #8b847a;
    font-size: 10px;
    font-weight: 650;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.campaign-person-row {
    min-height: 72px;
    border-top: 1px solid var(--ob-border-soft);
    color: #4d4841;
    font-size: 11px;
}
.campaign-person {
    display: grid;
    min-width: 0;
    gap: 3px;
}
.campaign-person strong {
    overflow: hidden;
    color: #25211c;
    font-size: 12px;
    font-weight: 640;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.campaign-person small {
    overflow: hidden;
    color: #817a71;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.campaign-person-company {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.campaign-person-row select {
    width: 100%;
    min-width: 0;
    min-height: 36px;
    max-width: 100%;
    border: 1px solid var(--ob-border);
    border-radius: 8px;
    padding: 0 10px;
    background: #fff;
    color: #302c26;
    font: inherit;
    font-size: 11px;
}
.campaign-person-status {
    display: inline-flex;
    border-radius: 999px;
    padding: 3px 7px;
    background: #efede9;
    color: #665f56;
    font-size: 9px;
    font-weight: 650;
    text-transform: capitalize;
}
.campaign-person-status.tone-positive {
    background: #e4f7ef;
    color: #167a57;
}
.campaign-person-status.tone-negative {
    background: #ffe9e5;
    color: #a33a31;
}
.campaign-person-status.tone-attention {
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
}
.campaign-person-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}
.campaign-thread-link,
.campaign-settings-link {
    color: var(--ob-accent-strong);
    font-size: 10px;
    font-weight: 620;
    text-decoration: none;
}
.row-menu {
    position: relative;
}
.row-menu summary {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 7px;
    color: #746d64;
    cursor: pointer;
    list-style: none;
}
.row-menu summary:hover {
    background: #f1eee8;
}
.row-menu summary::-webkit-details-marker {
    display: none;
}
.row-menu summary svg {
    width: 17px;
    height: 17px;
}
.row-menu > div {
    position: absolute;
    right: 0;
    z-index: 20;
    display: grid;
    width: 190px;
    border: 1px solid #ddd7ce;
    border-radius: 8px;
    padding: 5px;
    background: #fff;
    box-shadow: 0 12px 30px rgb(42 36 29 / 14%);
}
.row-menu button {
    min-height: 38px;
    border: 0;
    border-radius: 6px;
    padding: 0 10px;
    background: transparent;
    color: #4d4841;
    font: inherit;
    font-size: 11px;
    text-align: left;
    cursor: pointer;
}
.row-menu button:hover {
    background: #f4f1ec;
}
.row-menu button.danger,
.campaign-text-danger {
    color: #a33a31;
}
.campaign-inline-empty {
    display: grid;
    min-height: 340px;
    place-content: center;
    justify-items: center;
    text-align: center;
}
.campaign-inline-empty > svg {
    width: 28px;
    height: 28px;
    margin-bottom: 14px;
    color: #8c857c;
}
.campaign-inline-empty h3 {
    margin: 0;
    color: #302c26;
    font-size: 16px;
}
.campaign-inline-empty p {
    max-width: 430px;
    margin: 8px 0 18px;
    color: #746d64;
    font-size: 12px;
    line-height: 1.5;
}
.sequence-version-line {
    margin: 8px 0 0;
    color: var(--ob-accent-strong);
    font-size: 12px;
    font-weight: 620;
}
.sequence-version-tag {
    display: inline-block;
    margin-left: 8px;
    border-radius: 999px;
    padding: 1px 7px;
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
    font-size: 10px;
    font-weight: 650;
    vertical-align: middle;
}
.sequence-workspace {
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr);
    min-height: 520px;
    margin-top: 26px;
    border: 1px solid #dfd9d0;
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
}
.sequence-steps {
    padding: 10px;
    border-right: 1px solid #e5dfd6;
    background: #faf8f4;
}
.sequence-step-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: stretch;
    border-radius: 8px;
}
.sequence-step-row.active,
.sequence-step-row:hover {
    background: var(--ob-accent-tint);
    color: var(--ob-accent-strong);
}
.sequence-steps .sequence-step-row > button {
    display: grid;
    width: 100%;
    min-height: 64px;
    grid-template-columns: 27px minmax(0, 1fr);
    align-items: center;
    gap: 9px;
    border: 0;
    border-radius: 8px;
    padding: 8px;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.sequence-reorder {
    display: grid;
    align-content: center;
    gap: 2px;
    padding: 6px 4px 6px 0;
}
.sequence-reorder button {
    display: grid;
    width: 22px;
    height: 22px;
    place-items: center;
    border: 0;
    border-radius: 5px;
    padding: 0;
    background: transparent;
    color: #746d64;
    cursor: pointer;
}
.sequence-reorder button:hover:not(:disabled) {
    background: var(--ob-accent-tint-strong);
    color: var(--ob-accent-strong);
}
.sequence-reorder button:disabled {
    opacity: 0.35;
    cursor: default;
}
.sequence-reorder svg {
    width: 14px;
    height: 14px;
}
.sequence-position {
    display: grid;
    width: 26px;
    height: 26px;
    place-items: center;
    border: 1px solid #d8d1c8;
    border-radius: 50%;
    background: #fff;
    font-size: 10px;
    font-weight: 650;
}
.sequence-steps .sequence-step-row > button > span:last-child {
    display: grid;
    min-width: 0;
    gap: 3px;
}
.sequence-steps strong,
.sequence-steps small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sequence-steps strong {
    font-size: 11px;
}
.sequence-steps small {
    color: #817a71;
    font-size: 9px;
}
.sequence-editor {
    min-width: 0;
}
.sequence-editor-bar {
    display: flex;
    min-height: 58px;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0 18px;
    border-bottom: 1px solid #e5dfd6;
}
.campaign-segmented {
    display: flex;
    gap: 2px;
    padding: 3px;
    border-radius: 7px;
    background: #f0ede7;
}
.campaign-segmented button {
    min-height: 32px;
    border: 0;
    border-radius: 5px;
    padding: 0 12px;
    background: transparent;
    color: #6f685f;
    font: inherit;
    font-size: 10px;
    cursor: pointer;
}
.campaign-segmented button.active {
    background: #fff;
    color: #302c26;
    box-shadow: 0 1px 3px rgb(38 33 27 / 10%);
}
.sequence-editor-bar > select {
    min-height: 36px;
    max-width: 230px;
    font-size: 10px;
}
.sequence-fields {
    display: grid;
    gap: 18px;
    padding: 22px;
}
.sequence-fields > label,
.sequence-settings-row label {
    display: grid;
    gap: 7px;
    color: #4d4841;
    font-size: 11px;
    font-weight: 620;
}
.sequence-fields input,
.sequence-fields select {
    min-height: 40px;
}
.sequence-fields textarea {
    min-height: 180px;
}
.sequence-fields label small {
    color: #817a71;
    font-weight: 400;
    line-height: 1.4;
}
.sequence-fields code {
    border-radius: 4px;
    padding: 2px 4px;
    background: #f0ede7;
    color: var(--ob-accent-strong);
}
.sequence-settings-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.sequence-test-row {
    display: grid;
    grid-template-columns:
        minmax(130px, 1fr) minmax(150px, 1fr) minmax(180px, 1.2fr)
        auto;
    align-items: end;
    gap: 9px;
    padding-top: 18px;
    border-top: 1px solid #e8e2da;
}
.sequence-test-row > div {
    display: grid;
    gap: 3px;
}
.sequence-test-row strong {
    font-size: 11px;
}
.sequence-test-row small {
    color: #817a71;
    font-size: 9px;
}
.sequence-danger-row {
    display: flex;
    justify-content: flex-end;
}
.campaign-text-danger {
    min-height: 36px;
    border: 0;
    background: transparent;
    font: inherit;
    font-size: 10px;
    cursor: pointer;
}
.sequence-preview {
    padding: 22px;
}
.sequence-preview-meta {
    display: grid;
    gap: 10px;
    padding-bottom: 18px;
    border-bottom: 1px solid #e8e2da;
}
.sequence-preview-meta span {
    display: grid;
    grid-template-columns: 58px minmax(0, 1fr);
    gap: 10px;
    color: #817a71;
    font-size: 10px;
}
.sequence-preview-meta strong {
    color: #302c26;
    font-weight: 620;
}
.sequence-preview-body {
    min-height: 260px;
    padding: 28px 0;
    color: #302c26;
    font-size: 13px;
    line-height: 1.7;
    white-space: pre-wrap;
}
.sequence-preview > p {
    margin: 0;
    padding-top: 14px;
    border-top: 1px solid #e8e2da;
    color: #817a71;
    font-size: 10px;
}
.sequence-preview-error {
    margin: 0;
    color: #a33a31;
    font-size: 13px;
    line-height: 1.5;
}
.sending-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(260px, 0.7fr);
    gap: 42px;
    margin-top: 28px;
}
.sending-setup > label {
    display: block;
    margin-bottom: 7px;
    color: #4d4841;
    font-size: 11px;
    font-weight: 620;
}
.sending-capacity-card,
.sending-identities-card {
    display: grid;
    gap: 8px;
    margin-bottom: 28px;
    padding: 18px;
    border: 1px solid #e3ddd4;
    border-radius: 10px;
    background: #fbfaf7;
}
.sending-capacity-card h3,
.sending-identities-card h3 {
    margin: 0;
    color: #302c26;
    font-size: 18px;
}
.sending-capacity-card > label,
.sending-identities-card > label {
    margin-top: 4px;
    color: #4d4841;
    font-size: 11px;
    font-weight: 620;
}
.sending-capacity-card > input,
.sending-identities-card > select {
    width: 100%;
    min-height: 42px;
    padding: 9px 12px;
    border: 1px solid #d8d1c7;
    border-radius: 6px;
    background: #fff;
}
.sending-capacity-card > p:not(.campaign-eyebrow),
.sending-identities-card > p:not(.campaign-eyebrow) {
    margin: 0;
    color: #746d64;
    font-size: 11px;
    line-height: 1.5;
}
.sending-identities-empty {
    display: grid;
    gap: 8px;
}
.sending-capacity-summary {
    color: #746d64;
    font-size: 11px;
    line-height: 1.5;
}
.sending-capacity-summary strong {
    color: #302c26;
    font-size: 12px;
}
.sending-capacity-warning {
    color: #a33a31 !important;
}
.sending-capacity-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 14px;
    margin-top: 4px;
}
.sending-capacity-actions .btn {
    min-height: 34px;
}
.sending-advanced {
    margin-top: 20px;
    border-top: 1px solid #e3ddd4;
    padding-top: 12px;
}
.sending-advanced > summary {
    color: #4d4841;
    cursor: pointer;
    font-size: 11px;
    font-weight: 620;
}
.sending-permission {
    margin-top: 22px;
}
.sending-advanced .sending-tracking {
    margin-top: 14px;
}
.sending-setup > select {
    width: 100%;
    min-height: 42px;
}
.sending-mailboxes {
    display: grid;
    margin-top: 8px;
    border-top: 1px solid #e3ddd4;
}
.sending-mailboxes > label {
    display: grid;
    min-height: 70px;
    grid-template-columns: 20px minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #e8e2da;
    cursor: pointer;
}
.sending-mailboxes > label.unavailable {
    opacity: 0.65;
}
.sending-mailbox-copy {
    display: grid;
    min-width: 0;
    gap: 3px;
}
.sending-mailbox-copy strong,
.sending-mailbox-copy small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sending-mailbox-copy strong {
    color: #302c26;
    font-size: 11px;
}
.sending-mailbox-copy small {
    color: #817a71;
    font-size: 9px;
}
.sending-mailbox-status {
    border-radius: 999px;
    padding: 3px 7px;
    background: #fff0ea;
    color: #a33a31;
    font-size: 9px;
    font-weight: 650;
}
.sending-mailbox-status.ready {
    background: #e4f7ef;
    color: #167a57;
}
.sending-tracking {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 16px;
}
.sending-setup > label.sending-tracking {
    display: flex;
}

.sending-tracking input {
    width: auto;
    flex: 0 0 auto;
    margin-top: 3px;
}

.sending-tracking span {
    display: grid;
    gap: 4px;
}

.sending-tracking small {
    color: #6d675e;
}
.sending-window {
    margin: 20px 0 0;
    padding: 18px;
    border: 1px solid #e3ddd4;
    border-radius: 10px;
}
.sending-window legend {
    padding: 0 6px;
    color: #4d4841;
    font-size: 11px;
    font-weight: 620;
}
.sending-window-times {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.sending-window-times label {
    display: grid;
    gap: 6px;
}
.sending-window-times span {
    color: #6d675e;
    font-size: 10px;
}
.sending-window-times input {
    width: 100%;
}
.sending-window .sending-tracking {
    margin-top: 14px;
}

.sending-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 20px;
}
.sending-schedule {
    align-self: start;
    border-left: 1px solid #e3ddd4;
    padding-left: 28px;
}
.sending-schedule > summary {
    color: #4d4841;
    cursor: pointer;
    font-size: 11px;
    font-weight: 620;
}
.sending-schedule h3 {
    margin: 0;
    color: #302c26;
    font-size: 22px;
}
.sending-schedule > p:not(.campaign-eyebrow) {
    margin: 5px 0 20px;
    color: #746d64;
    font-size: 11px;
}
.sending-schedule > div {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 12px;
    color: #5b554d;
    font-size: 10px;
    line-height: 1.45;
}
.sending-schedule > div svg {
    width: 14px;
    height: 14px;
    flex: 0 0 14px;
    color: #167a57;
}
.sending-schedule > small {
    display: block;
    margin-top: 20px;
    color: #918a80;
    font-size: 9px;
    line-height: 1.5;
}
.campaign-modal {
    max-width: 560px;
}
.campaign-modal-wide {
    max-width: 680px;
}
.add-people-modes {
    margin-bottom: 18px;
}
.campaign-import-shortcut {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid #e5dfd5;
    color: #746d64;
    font-size: 11px;
}
.campaign-import-shortcut label,
.campaign-import-file {
    cursor: pointer;
}
.campaign-import-shortcut input,
.campaign-import-file input {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}
.campaign-person-link {
    color: inherit;
    text-decoration: none;
}
.campaign-person-link:hover {
    color: var(--ob-accent-strong);
}
.enrollment-summary {
    margin: 0 0 16px;
}
.campaign-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
}
.campaign-modal-head > div:first-child {
    min-width: 0;
    flex: 1;
}
.campaign-modal-head h2 {
    margin: 0;
    color: #302c26;
    font-size: 20px;
}
.campaign-modal-head button {
    display: grid;
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    place-items: center;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #746d64;
    cursor: pointer;
}
.campaign-modal-head button:hover {
    background: #f2efe9;
}
.campaign-modal-head svg {
    width: 18px;
    height: 18px;
}
.campaign-modal > label {
    display: block;
    margin-bottom: 7px;
    color: #4d4841;
    font-size: 11px;
    font-weight: 620;
}
.campaign-modal > textarea {
    width: 100%;
    min-height: 160px;
}
.campaign-form-help {
    margin: 9px 0 0;
    color: #817a71;
    font-size: 10px;
    line-height: 1.5;
}
.campaign-form-help a {
    color: var(--ob-accent-strong);
}
.campaign-dialog-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 24px;
}
.campaign-confirm-modal {
    position: relative;
    max-width: 440px;
    padding: 24px 24px 20px;
}
.campaign-confirm-modal .campaign-eyebrow {
    margin: 0 0 8px !important;
    padding-right: 36px;
}
.campaign-confirm-modal h2 {
    margin: 0 0 10px;
    padding-right: 36px;
    color: #302c26;
    font-size: 20px;
    font-weight: 650;
    letter-spacing: -0.02em;
    line-height: 1.25;
}
.campaign-confirm-close {
    position: absolute;
    top: 14px;
    right: 14px;
    display: grid;
    width: 36px;
    height: 36px;
    place-items: center;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #746d64;
    cursor: pointer;
}
.campaign-confirm-close:hover,
.campaign-confirm-close:focus-visible {
    background: #f2efe9;
}
.campaign-confirm-close svg {
    width: 18px;
    height: 18px;
}
.campaign-confirm-body {
    margin: 0;
    color: #746d64;
    font-size: 13px;
    line-height: 1.55;
}
.import-results {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-top: 1px solid #e3ddd4;
    border-bottom: 1px solid #e3ddd4;
}
.import-results > div {
    display: grid;
    gap: 4px;
    padding: 18px 10px;
    text-align: center;
}
.import-results > div + div {
    border-left: 1px solid #e8e2da;
}
.import-results strong {
    color: #302c26;
    font-size: 20px;
}
.import-results span {
    color: #817a71;
    font-size: 9px;
}
.import-results .ready strong {
    color: #167a57;
}
.launch-review {
    max-width: 600px;
}
.launch-review-intro {
    margin: 0 0 16px;
    color: #746d64;
    font-size: 11px;
    line-height: 1.5;
}
.launch-checks {
    border-top: 1px solid #e3ddd4;
}
.launch-checks > button {
    display: grid;
    width: 100%;
    min-height: 64px;
    grid-template-columns: 30px minmax(0, 1fr) 18px;
    align-items: center;
    gap: 11px;
    border: 0;
    border-bottom: 1px solid #e8e2da;
    background: transparent;
    color: #302c26;
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.launch-checks > button > span:nth-child(2) {
    display: grid;
    gap: 3px;
}
.launch-checks strong {
    font-size: 11px;
}
.launch-checks small {
    color: #746d64;
    font-size: 9px;
}
.launch-checks > button > svg {
    width: 15px;
    color: #918a80;
}
.launch-schedule {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 18px;
    border-radius: 8px;
    padding: 12px;
    background: #f6f3ee;
}
.launch-schedule > svg {
    width: 16px;
    color: var(--ob-accent-strong);
}
.launch-schedule > span {
    display: grid;
    gap: 4px;
}
.launch-schedule strong {
    font-size: 10px;
}
.launch-schedule small {
    color: #746d64;
    font-size: 9px;
}
.launch-safety-note {
    margin: 14px 0 0;
    color: #746d64;
    font-size: 10px;
    line-height: 1.5;
}
@media (max-width: 1100px) {
    .campaign-detail {
        padding: 28px 26px 48px;
    }
    .campaign-people-head {
        display: none;
    }
    .campaign-person-row {
        grid-template-columns:
            minmax(180px, 1.2fr) minmax(105px, 0.7fr) minmax(180px, 1fr)
            auto;
    }
    .campaign-person-company {
        display: none;
    }
    .sequence-test-row {
        grid-template-columns: 1fr 1fr;
    }
}
@media (max-width: 760px) {
    :global(.side-campaigns) {
        min-height: 0;
        padding: 58px 12px 10px;
    }
    :global(.side-campaigns .side-title) {
        position: absolute;
        top: 20px;
        left: 58px;
        margin: 0;
        color: #29251f;
        font-size: 13px;
        letter-spacing: normal;
        text-transform: none;
    }
    .campaign-back,
    .campaign-side-status {
        display: none;
    }
    .campaign-section-nav {
        display: flex;
        gap: 6px;
        padding: 0;
        overflow-x: auto;
        scrollbar-width: none;
    }
    .campaign-section-nav::-webkit-scrollbar {
        display: none;
    }
    .campaign-section-nav button {
        min-width: max-content;
        min-height: 44px;
        gap: 10px;
        padding-inline: 14px;
    }
    .campaign-replies-link {
        display: none;
    }
    .campaign-detail {
        padding: 24px 18px 40px;
    }
    .campaign-summary-strip {
        grid-template-columns: 1fr 1fr;
        row-gap: 20px;
    }
    .campaign-summary-strip > div:nth-child(3) {
        padding-left: 0;
        border-left: 0;
    }
    .campaign-overview-grid,
    .sending-layout {
        grid-template-columns: 1fr;
        gap: 28px;
    }
    .campaign-next-step,
    .sending-schedule {
        border-top: 1px solid #e3ddd4;
        border-left: 0;
        padding: 24px 0 0;
    }
    .campaign-section-heading-row {
        align-items: flex-start;
        flex-direction: column;
    }
    .campaign-person-row {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        padding: 13px 0;
    }
    .campaign-person-row > span:nth-child(2),
    .campaign-person-row > span:nth-child(4) {
        display: none;
    }
    .sequence-workspace {
        grid-template-columns: 1fr;
    }
    .sequence-steps {
        display: flex;
        border-right: 0;
        border-bottom: 1px solid #e5dfd6;
        overflow-x: auto;
    }
    .sequence-step-row {
        min-width: 190px;
    }
    .sequence-settings-row,
    .sequence-test-row {
        grid-template-columns: 1fr;
    }
    .sequence-editor-bar {
        align-items: flex-start;
        flex-direction: column;
        padding: 12px;
    }
    .sequence-editor-bar > select {
        width: 100%;
        max-width: none;
    }
}
</style>
