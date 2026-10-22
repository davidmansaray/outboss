import { describe, expect, it } from 'vitest';
import { isStandaloneWorkspacePage } from '@/lib/pageLayout';

describe('page layout selection', () => {
    it('does not wrap complete workspace pages in the starter layout', () => {
        expect(isStandaloneWorkspacePage('objectives/Index')).toBe(true);
        expect(isStandaloneWorkspacePage('execution-plans/Show')).toBe(true);
        expect(isStandaloneWorkspacePage('campaigns/Index')).toBe(true);
        expect(isStandaloneWorkspacePage('accounts/Intelligence')).toBe(true);
        expect(isStandaloneWorkspacePage('prospect-sources/Index')).toBe(true);
        expect(isStandaloneWorkspacePage('prospect-sources/Show')).toBe(true);
        expect(isStandaloneWorkspacePage('prospect-sources/Run')).toBe(true);
    });

    it('keeps generic pages eligible for the default application layout', () => {
        expect(isStandaloneWorkspacePage('dashboard/Index')).toBe(false);
    });
});
