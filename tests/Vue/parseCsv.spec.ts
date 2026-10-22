import { describe, expect, it } from 'vitest';
import { csvTable, guessCampaignImportMapping } from '@/lib/parseCsv';

describe('campaign CSV imports', () => {
    it('detects common campaign fields regardless of column order', () => {
        const table = csvTable(
            'Store,Email Address,Given Name,Full Name\nAcme,maya@acme.test,Maya,Maya Chen',
        );

        expect(table.rows).toEqual([
            {
                Store: 'Acme',
                'Email Address': 'maya@acme.test',
                'Given Name': 'Maya',
                'Full Name': 'Maya Chen',
            },
        ]);
        expect(guessCampaignImportMapping(table.headers)).toEqual({
            email: 'Email Address',
            name: 'Full Name',
            first_name: 'Given Name',
            company: '',
        });
    });

    it('parses quoted fields without splitting embedded commas', () => {
        const table = csvTable(
            'email,name,company\nmaya@acme.test,"Chen, Maya","Acme, Inc."',
        );

        expect(table.rows[0]).toMatchObject({
            email: 'maya@acme.test',
            name: 'Chen, Maya',
            company: 'Acme, Inc.',
        });
    });

    it('keeps duplicate headers distinct instead of overwriting data', () => {
        const table = csvTable(
            'email,email,company\nprimary@acme.test,backup@acme.test,Acme',
        );

        expect(table.headers).toEqual(['email', 'email (2)', 'company']);
        expect(table.rows[0]).toMatchObject({
            email: 'primary@acme.test',
            'email (2)': 'backup@acme.test',
            company: 'Acme',
        });
        expect(guessCampaignImportMapping(table.headers).email).toBe('email');
    });

    it('avoids collisions with headers that already contain a generated suffix', () => {
        const table = csvTable(
            'email,email,email (2)\nfirst@acme.test,second@acme.test,third@acme.test',
        );

        expect(table.headers).toEqual(['email', 'email (2)', 'email (2) (2)']);
        expect(Object.values(table.rows[0])).toEqual([
            'first@acme.test',
            'second@acme.test',
            'third@acme.test',
        ]);
    });

    it('uses the campaign three-column layout for headerless data', () => {
        const table = csvTable(
            'maya@acme.test,Maya Chen,Acme\naisha@example.test,Aisha,Example',
            ['email', 'name', 'company'],
        );

        expect(table.headers).toEqual(['email', 'name', 'company']);
        expect(table.rows[0]).toMatchObject({
            email: 'maya@acme.test',
            name: 'Maya Chen',
            company: 'Acme',
        });
    });
});
