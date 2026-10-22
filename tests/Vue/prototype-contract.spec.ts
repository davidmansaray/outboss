import { describe, expect, it } from 'vitest';

const contract = {
    rail: ['/unibox', '/campaigns', '/settings/mailboxes'],
    uniboxFolders: ['needs', 'all_open', 'ooo', 'problems', 'done'],
    uniboxActions: [
        'threads.reply',
        'threads.done',
        'threads.classify',
        'messages.store',
        'tracking.pixel',
    ],
    campaigns: [
        'campaigns.store',
        'campaigns.launch',
        'campaigns.pause',
        'campaigns.resume',
        'campaigns.imports.preview',
        'campaigns.imports.commit',
        'campaigns.steps.store',
        'campaigns.steps.reorder',
        'sequence-steps.preview',
        'sequence-steps.update',
        'sequence-steps.destroy',
        'sequence-steps.test',
        'campaign-recipients.update',
        'campaign-recipients.destroy',
    ],
    settings: ['mailboxes.index', 'suppressions.index', 'oauth.redirect'],
};

describe('prototype interaction contract', () => {
    it('maps every prototype area to a production route family', () => {
        expect(contract.rail).toHaveLength(3);
        expect(contract.uniboxFolders).toContain('needs');
        expect(contract.campaigns).toContain('campaigns.resume');
        expect(contract.settings).toContain('suppressions.index');
    });
});
