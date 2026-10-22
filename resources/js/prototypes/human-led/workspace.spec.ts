import { describe, expect, it } from 'vitest';
import {
    approve,
    convert,
    fulfil,
    launch,
    receiveReply,
    sendReply,
} from './workflow';
import {
    addCampaign,
    addObjective,
    addOffer,
    newWorkspace,
    objectiveProgress,
    refreshDrafts,
} from './workspace';
import type { Campaign } from './workspace';
function prepare(c: Campaign, person = 'p1') {
    c.workflow.draft.selected = [person];
    c.workflow.draft.subject = 'Hello';
    c.workflow.draft.body = 'A workshop?';
}
function complete(c: Campaign, person = 'p1') {
    prepare(c, person);
    approve(c.workflow);
    launch(c.workflow);
    receiveReply(c.workflow);
    sendReply(c.workflow);
    fulfil(c.workflow);
    convert(c.workflow);
}
describe('independent reusable primitives', () => {
    it('does not reinterpret old outcomes when an Objective changes its success definition', () => {
        const w = newWorkspace();
        complete(addCampaign(w, 'A', 'objective-1', 'offer-1'));
        expect(objectiveProgress(w, 'objective-1')).toBe(1);
        w.objectives[0].success = 'Workshop booked';
        refreshDrafts(w);
        expect(objectiveProgress(w, 'objective-1')).toBe(0);
        expect(w.campaigns[0].workflow.converted).toBe(true);
    });
    it('creates independent entities without campaign side effects', () => {
        const w = newWorkspace();
        addOffer(w, {
            name: 'Offer',
            promise: 'Promise',
            owner: 'Alex',
            capacity: 2,
            fulfilment: 'Workshop',
        });
        addObjective(w, {
            name: 'Goal',
            target: 2,
            success: 'Workshop booked',
            endsAt: '2026-10-31',
            budget: 20,
        });
        expect(w.offers).toHaveLength(3);
        expect(w.objectives).toHaveLength(3);
        expect(w.campaigns).toHaveLength(0);
    });
    it('reuses references without duplicating entities and keeps campaign audiences separate', () => {
        const w = newWorkspace();
        const a = addCampaign(w, 'A', 'objective-1', 'offer-1');
        const b = addCampaign(w, 'B', 'objective-1', 'offer-1');
        prepare(a);
        prepare(b, 'p3');
        expect(w.offers).toHaveLength(2);
        expect(w.objectives).toHaveLength(2);
        expect(a.workflow.draft.selected).toEqual(['p1']);
        expect(b.workflow.draft.selected).toEqual(['p3']);
        expect(a.workflow.draft.endsAt).toBe(b.workflow.draft.endsAt);
    });
    it('aggregates unique bookings and does not reset progress for a new campaign', () => {
        const w = newWorkspace();
        complete(addCampaign(w, 'A', 'objective-1', 'offer-1'));
        expect(objectiveProgress(w, 'objective-1')).toBe(1);
        const b = addCampaign(w, 'B', 'objective-1', 'offer-2');
        expect(objectiveProgress(w, 'objective-1')).toBe(1);
        complete(b, 'p3');
        expect(objectiveProgress(w, 'objective-1')).toBe(2);
        complete(
            addCampaign(w, 'Duplicate evidence', 'objective-1', 'offer-1'),
        );
        expect(objectiveProgress(w, 'objective-1')).toBe(2);
        expect(objectiveProgress(w, 'objective-2')).toBe(0);
    });
    it('refreshes draft references and invalidates approval without rewriting launched snapshots', () => {
        const w = newWorkspace();
        const live = addCampaign(w, 'Live', 'objective-1', 'offer-1');
        prepare(live);
        approve(live.workflow);
        launch(live.workflow);
        const draft = addCampaign(w, 'Draft', 'objective-1', 'offer-1');
        prepare(draft);
        approve(draft.workflow);
        const prior = live.workflow.draft.promise;
        w.offers[0].promise = 'A new promise';
        w.offers[0].version++;
        refreshDrafts(w);
        expect(live.workflow.draft.promise).toBe(prior);
        expect(live.workflow.draft.offerVersion).toBe(1);
        expect(draft.workflow.draft.promise).toBe('A new promise');
        expect(draft.workflow.draft.offerVersion).toBe(2);
        expect(draft.workflow.approval).toBeNull();
    });
});
