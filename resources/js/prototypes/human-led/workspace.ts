import { newWorkflow } from './workflow';
import type { Workflow } from './workflow';

export type Offer = {
    id: string;
    version: number;
    name: string;
    promise: string;
    owner: string;
    capacity: number;
    fulfilment: string;
};
export type Objective = {
    id: string;
    version: number;
    name: string;
    target: number;
    success: string;
    endsAt: string;
    budget: number;
};
export type Campaign = {
    id: string;
    name: string;
    objectiveId: string;
    offerId: string;
    workflow: Workflow;
};
export type Workspace = {
    offers: Offer[];
    objectives: Objective[];
    campaigns: Campaign[];
    nextId: number;
};

export function newWorkspace(): Workspace {
    const example = newWorkflow().draft;

    return {
        nextId: 3,
        offers: [
            {
                id: 'offer-1',
                version: 1,
                name: example.offer,
                promise: example.promise,
                owner: example.owner,
                capacity: 5,
                fulfilment: example.fulfilment,
            },
            {
                id: 'offer-2',
                version: 1,
                name: 'Outbound campaign review',
                promise:
                    'A written review of your existing outreach with three practical improvements.',
                owner: 'Alex Morgan',
                capacity: 10,
                fulfilment: 'Human-delivered audit',
            },
        ],
        objectives: [
            {
                id: 'objective-1',
                version: 1,
                name: 'Book 6 qualified agency meetings',
                target: 6,
                success: 'Qualified meeting booked',
                endsAt: '2026-10-04',
                budget: 150,
            },
            {
                id: 'objective-2',
                version: 1,
                name: 'Book 3 founder workshops',
                target: 3,
                success: 'Workshop booked',
                endsAt: '2026-10-31',
                budget: 75,
            },
        ],
        campaigns: [],
    };
}
export function addOffer(
    w: Workspace,
    data: Omit<Offer, 'id' | 'version'>,
): Offer {
    const offer = { ...data, id: `offer-${w.nextId++}`, version: 1 };
    w.offers.push(offer);

    return offer;
}
export function addObjective(
    w: Workspace,
    data: Omit<Objective, 'id' | 'version'>,
): Objective {
    const objective = { ...data, id: `objective-${w.nextId++}`, version: 1 };
    w.objectives.push(objective);

    return objective;
}
export function addCampaign(
    w: Workspace,
    name: string,
    objectiveId = '',
    offerId = '',
): Campaign {
    const workflow = newWorkflow();
    workflow.draft.selected = [];
    workflow.draft.subject = '';
    workflow.draft.body = '';
    const campaign = {
        id: `campaign-${w.nextId++}`,
        name,
        objectiveId,
        offerId,
        workflow,
    };
    workflow.contextId = campaign.id;
    w.campaigns.push(campaign);
    syncCampaign(w, campaign);

    return campaign;
}
/** Drafts reference current entity versions. A launched campaign retains its snapshot. */
export function syncCampaign(w: Workspace, c: Campaign): void {
    if (c.workflow.launched) {
        return;
    }

    const offer = w.offers.find((o) => o.id === c.offerId);
    const objective = w.objectives.find((o) => o.id === c.objectiveId);
    const d = c.workflow.draft;
    Object.assign(d, {
        offer: offer?.name ?? '',
        promise: offer?.promise ?? '',
        owner: offer?.owner ?? '',
        fulfilment: offer?.fulfilment ?? '',
        capacity: offer?.capacity ?? 0,
        objective: objective?.name ?? '',
        target: objective?.target ?? 0,
        success: objective?.success ?? '',
        budget: objective?.budget ?? 0,
        objectiveId: objective?.id ?? '',
        offerId: offer?.id ?? '',
        objectiveVersion: objective?.version ?? 0,
        offerVersion: offer?.version ?? 0,
        endsAt: objective?.endsAt ?? '',
    });

    if (c.workflow.approval && c.workflow.approval !== JSON.stringify(d)) {
        c.workflow.approval = null;
        c.workflow.activity.push({
            label: 'Previous approval invalidated',
            detail: 'Linked entity or campaign inputs changed. Fresh approval is required.',
        });
    }
}
export function refreshDrafts(w: Workspace): void {
    w.campaigns.forEach((c) => syncCampaign(w, c));
}
/** One sample booking per person + success definition; never count it twice across campaigns. */
export function objectiveProgress(w: Workspace, id: string): number {
    const objective = w.objectives.find((o) => o.id === id);

    return new Set(
        w.campaigns
            .filter(
                (c) =>
                    c.objectiveId === id &&
                    c.workflow.converted &&
                    c.workflow.draft.success === objective?.success,
            )
            .map(
                (c) => `${c.workflow.accepted[0]}:${c.workflow.draft.success}`,
            ),
    ).size;
}
