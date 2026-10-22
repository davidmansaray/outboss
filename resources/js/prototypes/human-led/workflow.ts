/** Isolated design-prototype state. Never calls production APIs or mail providers. */
export type Person = {
    id: string;
    name: string;
    company: string;
    role: string;
    email: string;
    reason: string;
    excluded?: string;
};
export const people: Person[] = [
    {
        id: 'p1',
        name: 'Maya Chen',
        company: 'Northstar Studio',
        role: 'Founder',
        email: 'maya@northstar.example',
        reason: 'Agency founder · 12-person team · supplied list',
    },
    {
        id: 'p2',
        name: 'Oliver Grant',
        company: 'Fieldwork',
        role: 'Managing director',
        email: 'oliver@fieldwork.example',
        reason: 'Agency director · 18-person team · supplied list',
    },
    {
        id: 'p3',
        name: 'Amara Okafor',
        company: 'Outline',
        role: 'Co-founder',
        email: 'amara@outline.example',
        reason: 'Agency founder · 9-person team · supplied list',
    },
    {
        id: 'p4',
        name: 'Leo Martin',
        company: 'Common Ground',
        role: 'Founder',
        email: 'leo@common.example',
        reason: 'Previously opted out',
        excluded: 'Suppressed · unsubscribed',
    },
    {
        id: 'p5',
        name: 'Sofia Reyes',
        company: 'Form & Function',
        role: 'Partner',
        email: 'sofia@form.example',
        reason: 'Source permissions need review',
        excluded: 'Contact permission missing',
    },
];
export const steps = [
    'Offer',
    'Objective',
    'Audience',
    'Message',
    'Review',
    'Conversations',
    'Outcomes',
];
export type Draft = {
    objectiveId?: string;
    offerId?: string;
    objectiveVersion?: number;
    offerVersion?: number;
    endsAt?: string;
    offer: string;
    promise: string;
    fulfilment: string;
    owner: string;
    capacity: number;
    objective: string;
    target: number;
    success: string;
    days: number;
    budget: number;
    selected: string[];
    subject: string;
    body: string;
    mailbox: string;
    daily: number;
};
export type Activity = { label: string; detail: string };
export type Workflow = {
    contextId?: string;
    draft: Draft;
    approval: string | null;
    launched: boolean;
    paused: boolean;
    accepted: string[];
    unknown: string[];
    replied: boolean;
    fulfilled: boolean;
    converted: boolean;
    reply: string;
    replySent: boolean;
    activity: Activity[];
};
export function newWorkflow(): Workflow {
    return {
        draft: {
            offer: 'Pipeline clarity workshop',
            promise:
                'A 30-minute working session and a written action plan for your next outbound campaign.',
            fulfilment: 'Human-led workshop',
            owner: 'Alex Morgan',
            capacity: 5,
            objective: 'Book 3 agency workshops',
            target: 3,
            success: 'Workshop booked',
            days: 30,
            budget: 50,
            selected: ['p1', 'p2', 'p3'],
            subject: 'An outbound idea for {{company}}',
            body: 'Hi {{first_name}},\n\nI work with agency founders on building a more consistent outbound pipeline.\n\nWould a 30-minute pipeline workshop be useful for {{company}}? We’ll map out one practical campaign and I’ll send you a written action plan afterwards.\n\nOpen to taking a look?\n\nAlex',
            mailbox: 'alex@studio.example',
            daily: 10,
        },
        approval: null,
        launched: false,
        paused: false,
        accepted: [],
        unknown: [],
        replied: false,
        fulfilled: false,
        converted: false,
        reply: 'Hi there,\n\nHappy to help. I can do Tuesday at 10:00 UTC or Wednesday at 14:00 UTC. Does either work for you?\n\nAlex',
        replySent: false,
        activity: [],
    };
}
export function selectedPeople(s: Workflow): Person[] {
    return people.filter((p) => s.draft.selected.includes(p.id) && !p.excluded);
}
export function blockers(s: Workflow, step = 4): string[] {
    const d = s.draft;
    const errors: string[] = [];

    if (
        !d.offer.trim() ||
        !d.promise.trim() ||
        !d.owner.trim() ||
        !Number.isInteger(d.capacity) ||
        d.capacity < 1
    ) {
        errors.push(
            'Add an Offer, a deliverable, an owner, and at least one available slot.',
        );
    }

    if (
        step >= 1 &&
        (!d.objective.trim() ||
            !Number.isInteger(d.target) ||
            d.target < 1 ||
            !Number.isInteger(d.days) ||
            d.days < 1 ||
            !Number.isFinite(d.budget) ||
            d.budget < 0)
    ) {
        errors.push(
            'Set an Objective, a positive target and time horizon, and a non-negative budget.',
        );
    }

    if (step >= 2 && selectedPeople(s).length === 0) {
        errors.push('Select at least one eligible prospect.');
    }

    if (
        step >= 2 &&
        d.selected.some((id) => !people.some((p) => p.id === id && !p.excluded))
    ) {
        errors.push('Remove ineligible recipients before approval.');
    }

    if (
        step >= 3 &&
        (!d.subject.trim() ||
            !d.body.trim() ||
            !Number.isInteger(d.daily) ||
            d.daily < 1 ||
            d.daily > 20)
    ) {
        errors.push(
            'Add a subject and message; set a daily limit between 1 and 20.',
        );
    }

    if (
        step >= 3 &&
        /\{\{|\}\}/.test(
            (d.subject + d.body).replace(
                /\{\{\s*(first_name|company)\s*\}\}/g,
                '',
            ),
        )
    ) {
        errors.push('Use only the supported first_name and company variables.');
    }

    return errors;
}
export function renderMessage(text: string, person: Person): string {
    return text
        .replace(/\{\{\s*first_name\s*\}\}/g, person.name.split(' ')[0])
        .replace(/\{\{\s*company\s*\}\}/g, person.company);
}
export function signature(s: Workflow): string {
    return JSON.stringify(s.draft);
}
export function isApproved(s: Workflow): boolean {
    return s.approval !== null && s.approval === signature(s);
}
export function approve(s: Workflow): boolean {
    if (s.launched || isApproved(s) || blockers(s).length) {
        return false;
    }

    s.approval = signature(s);
    recordActivity(s, {
        label: 'Exact plan approved',
        detail: 'Human approval · Offer, Objective, recipients, rendered messages and sending limits · plan-demo-01',
    });

    return true;
}
export function launch(s: Workflow): boolean {
    if (s.launched || !isApproved(s) || blockers(s).length) {
        return false;
    }

    s.launched = true;
    const selected = selectedPeople(s);
    s.accepted = selected.slice(0, 1).map((p) => p.id);
    s.unknown = selected.slice(1, 2).map((p) => p.id);
    recordActivity(s, {
        label: 'Demo campaign launched',
        detail: `${selected.length} approved recipients · campaign-demo-01`,
    });
    recordActivity(s, {
        label: 'Provider acceptance simulated',
        detail: `${selected[0].name} · exposure-demo-01 · send-demo-01 (not proof of delivery)`,
    });

    if (s.unknown.length) {
        recordActivity(s, {
            label: 'Send outcome unknown',
            detail: 'Second recipient held for reconciliation. No automatic retry and no exposure assumed.',
        });
    }

    return true;
}
export function receiveReply(s: Workflow): boolean {
    if (!s.launched || s.replied || !s.accepted.length) {
        return false;
    }

    s.replied = true;
    recordActivity(s, {
        label: 'Interested reply received',
        detail: 'Sample incoming reply · thread-demo-01 → exposure-demo-01. Further campaign outreach stopped for this person.',
    });

    return true;
}
export function sendReply(s: Workflow): boolean {
    if (!s.replied || s.replySent || !s.reply.trim()) {
        return false;
    }

    s.replySent = true;
    recordActivity(s, {
        label: 'Human reply simulated',
        detail: 'Exact reply reviewed and sent once · thread-demo-01',
    });

    return true;
}
export function fulfil(s: Workflow): boolean {
    if (!s.replySent || s.fulfilled) {
        return false;
    }

    s.fulfilled = true;
    recordActivity(s, {
        label: 'Booking confirmed by operator',
        detail: 'Sample customer accepted a workshop slot. Workshop delivery still outstanding.',
    });

    return true;
}
export function convert(s: Workflow): boolean {
    if (!s.fulfilled || s.converted) {
        return false;
    }

    s.converted = true;
    recordActivity(s, {
        label: 'Conversion recorded',
        detail: 'conversion-demo-01 · manual booking confirmation → thread-demo-01 → exposure-demo-01 → campaign-demo-01 → plan-demo-01. Association is not causal proof.',
    });

    return true;
}

function recordActivity(s: Workflow, event: Activity): void {
    s.activity.push({
        ...event,
        detail: s.contextId
            ? event.detail.replace(
                  /(?:plan|send|thread|exposure|campaign|conversion)-demo-\d+/g,
                  (id) => s.contextId + ':' + id,
              )
            : event.detail,
    });
}
