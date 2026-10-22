import { describe, expect, it } from 'vitest';
import {
    approve,
    blockers,
    convert,
    fulfil,
    isApproved,
    launch,
    newWorkflow,
    people,
    receiveReply,
    renderMessage,
    selectedPeople,
    sendReply,
} from './workflow';

describe('human-led prototype state', () => {
    it('requires exact approval, invalidates it after edits, and never launches twice', () => {
        const s = newWorkflow();
        expect(launch(s)).toBe(false);
        expect(approve(s)).toBe(true);
        s.draft.subject = 'Changed';
        expect(isApproved(s)).toBe(false);
        expect(launch(s)).toBe(false);
        expect(approve(s)).toBe(true);
        expect(launch(s)).toBe(true);
        expect(launch(s)).toBe(false);
        expect(s.accepted).toEqual(['p1']);
        expect(s.unknown).toEqual(['p2']);
    });
    it('rejects suppressed, unauthorized and missing recipients', () => {
        const s = newWorkflow();
        s.draft.selected.push('p4', 'p5', 'missing');
        expect(selectedPeople(s)).toHaveLength(3);
        expect(blockers(s)).toContain(
            'Remove ineligible recipients before approval.',
        );
        expect(approve(s)).toBe(false);
    });
    it('validates capacity, required fields, numbers, audience and variables', () => {
        const s = newWorkflow();
        s.draft.capacity = 1;
        s.draft.selected = [];
        s.draft.body = 'Hi {{unknown}}';
        s.draft.daily = 0;
        s.draft.budget = NaN;
        s.draft.offer = '';
        expect(blockers(s).length).toBeGreaterThanOrEqual(5);
        expect(approve(s)).toBe(false);
    });
    it('renders the recipient’s exact message', () => {
        expect(
            renderMessage('Hi {{first_name}} at {{company}}', people[0]),
        ).toBe('Hi Maya at Northstar Studio');
        expect(
            renderMessage('Hi {{ first_name }} at {{ company }}', people[0]),
        ).toBe('Hi Maya at Northstar Studio');
        const s = newWorkflow();
        s.draft.body = 'Hi {{ first_name }}';
        expect(blockers(s)).toEqual([]);
        s.draft.body = 'Hi {{company';
        expect(blockers(s)).toContain(
            'Use only the supported first_name and company variables.',
        );
    });
    it('requires booking evidence and records each follow-through action once', () => {
        const s = newWorkflow();
        expect(receiveReply(s)).toBe(false);
        expect(convert(s)).toBe(false);
        approve(s);
        launch(s);
        expect(receiveReply(s)).toBe(true);
        expect(receiveReply(s)).toBe(false);
        expect(convert(s)).toBe(false);
        expect(fulfil(s)).toBe(false);
        expect(sendReply(s)).toBe(true);
        expect(sendReply(s)).toBe(false);
        expect(fulfil(s)).toBe(true);
        expect(fulfil(s)).toBe(false);
        expect(convert(s)).toBe(true);
        expect(convert(s)).toBe(false);
        expect(
            s.activity.filter((e) => e.label === 'Conversion recorded'),
        ).toHaveLength(1);
        expect(s.unknown).toEqual(['p2']);
        expect(s.accepted).not.toContain('p2');
    });
    it('supports a one-person path without fabricating other sends', () => {
        const s = newWorkflow();
        s.draft.selected = ['p3'];
        approve(s);
        launch(s);
        expect(s.accepted).toEqual(['p3']);
        expect(s.unknown).toEqual([]);
    });
});
