import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const stylesheet = readFileSync('resources/css/app.css', 'utf8');

function rule(selector: string): string {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = stylesheet.match(
        new RegExp(`^${escaped} \\{([^}]+)\\}`, 'm'),
    );
    expect(match, `Missing layout rule for ${selector}`).not.toBeNull();

    return match![1];
}

describe('workspace scrolling contract', () => {
    // jsdom does not calculate layout. Guard the CSS contract here; verify
    // actual wheel scrolling and control reachability in the internal browser.
    it('lets long setup pages scroll inside the viewport-height app shell', () => {
        const workspace = rule('.workspace');
        expect(workspace).toMatch(/min-height:\s*0;/);
        expect(workspace).toMatch(/overflow-y:\s*auto;/);
        expect(workspace).toMatch(/overflow-x:\s*hidden;/);
        expect(workspace).not.toMatch(/overflow:\s*hidden;/);
    });

    it('preserves independent scrolling for the Inbox panes', () => {
        expect(rule('.inbox')).toMatch(/min-height:\s*0;/);
        expect(rule('.inbox')).toMatch(/flex:\s*1;/);
        expect(rule('.list')).toMatch(/overflow:\s*auto;/);
    });
});
