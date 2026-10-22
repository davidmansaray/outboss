import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    form: {
        name: '',
        website: '',
        notes: '',
        tags: [] as string[],
        confirmed_duplicate: false,
        email: '',
        full_name: '',
        job_title: '',
        account_id: null as number | null,
        processing: false,
        errors: {} as Record<string, string>,
        post: vi.fn(),
        patch: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
    },
    router: {
        delete: vi.fn(),
        post: vi.fn(),
    },
    page: {
        props: {
            flash: { personImport: null as null | Record<string, unknown> },
            errors: {} as Record<string, string>,
            auth: { workspace: { role: 'owner' } },
        },
    },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    state.form = reactive(state.form);
    state.page = reactive(state.page);

    return {
        Head: { name: 'Head', template: '<div />' },
        Link: {
            name: 'Link',
            props: ['href'],
            template: '<a :href="href"><slot /></a>',
        },
        router: state.router,
        useForm: () => state.form,
        usePage: () => state.page,
    };
});

const form = state.form;
const router = state.router;

import PeopleAccountsIndex from '@/pages/people/Index.vue';
import type { RegistryAccount, RegistryPerson } from '@/types';

const people: RegistryPerson[] = [
    {
        id: 1,
        name: 'Maya Chen',
        email: 'maya@northstar.studio',
        account_id: 11,
        account: 'Northstar Studio',
        role: 'Founder',
        reply: 'Interested',
        eligibility: 'Eligible',
        campaign_label: 'Founders',
        campaigns: [
            {
                id: 1,
                name: 'Founders',
                status: 'interested',
                updated_at: '2026-08-26T09:00:00Z',
            },
        ],
        tags: ['design'],
        notes: 'Met at SaaStr',
        source: 'Campaign enrolment',
        updated_at: '2026-08-26T09:00:00Z',
    },
    {
        id: 2,
        name: 'Sam Rivera',
        email: 'sam@orbital.test',
        account_id: 12,
        account: 'Orbital Works',
        role: null,
        reply: 'Awaiting reply',
        eligibility: 'Eligible',
        campaign_label: 'Founders',
        campaigns: [],
        tags: [],
        notes: null,
        source: 'Campaign enrolment',
        updated_at: '2026-08-25T09:00:00Z',
    },
];

const accounts: RegistryAccount[] = [
    {
        id: 11,
        name: 'Northstar Studio',
        domain: 'northstar.studio',
        website: 'https://northstar.studio',
        notes: null,
        tags: ['design'],
        source: 'manual',
        profile: null,
        employee_count: null,
        people_count: 1,
        ready_count: 1,
        readiness: 'Ready',
        campaigns_count: 1,
        people: [
            {
                id: 1,
                name: 'Maya Chen',
                email: 'maya@northstar.studio',
                role: 'Founder',
                reply: 'Interested',
            },
        ],
        updated_at: '2026-08-26T09:00:00Z',
    },
];

const stubs = {
    AppWorkspace: {
        props: ['area', 'title'],
        template:
            '<div><aside><slot name="sidebar" /></aside><slot name="crumb" /><slot /></div>',
    },
};

describe('People and accounts registry', () => {
    it('renders server-selected Accounts and follows Inertia view changes', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts, initialView: 'accounts' },
            global: { stubs },
        });
        expect(wrapper.get('h1').text()).toBe('Accounts');
        await wrapper.setProps({ initialView: 'people' });
        expect(wrapper.get('h1').text()).toBe('People');
        await wrapper.setProps({ initialView: 'accounts' });
        expect(wrapper.get('h1').text()).toBe('Accounts');
        expect(window.location.pathname).toBe('/accounts');
    });
    beforeEach(() => {
        window.history.replaceState({}, '', '/prospects');
        form.name = '';
        form.website = '';
        form.notes = '';
        form.tags = [];
        form.confirmed_duplicate = false;
        form.email = '';
        form.full_name = '';
        form.job_title = '';
        form.account_id = null;
        form.processing = false;
        form.errors = {};
        form.post.mockReset();
        form.patch.mockReset();
        router.delete.mockReset();
        router.post.mockReset();
        state.page.props.flash.personImport = null;
        state.page.props.errors = {};
        vi.stubGlobal(
            'matchMedia',
            vi.fn().mockImplementation(() => ({
                matches: false,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            })),
        );
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('opens the inspector for a preselected person', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: {
                people,
                accounts,
                preselect: { kind: 'person', id: 1 },
            },
            global: { stubs },
        });
        await flushPromises();

        expect(wrapper.get('.registry-inspector').classes()).toContain('open');
        expect(wrapper.get('.registry-inspector').text()).toContain(
            'Maya Chen',
        );
    });

    it('always shows account intelligence for an Account', async () => {
        const accountsWithProvenance: RegistryAccount[] = [
            {
                ...accounts[0],
                provenance: {
                    primary_source: {
                        id: 'source-1',
                        name: 'Founder Accounts import',
                    },
                    source_count: 1,
                    last_observed_at: '2026-08-26T09:00:00Z',
                    has_conflicts: false,
                    has_restrictions: false,
                    source_omission_reason: null,
                },
            },
        ];
        const wrapper = mount(PeopleAccountsIndex, {
            props: {
                people,
                accounts: accountsWithProvenance,
            },
            global: { stubs },
        });

        await wrapper.get('[data-registry-view="accounts"]').trigger('click');
        await wrapper.get('.row-entity-button').trigger('click');
        await flushPromises();

        expect(
            wrapper
                .get('[data-test="account-intelligence-link"]')
                .attributes('href'),
        ).toBe('/accounts/11/intelligence');
        expect(wrapper.get('.registry-inspector').text()).toContain(
            'Supplied byFounder Accounts import',
        );
    });

    it('switches between people and accounts without leaving the page', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        expect(wrapper.get('h1').text()).toBe('People');
        expect(wrapper.findAll('.registry-row')).toHaveLength(2);

        await wrapper.get('[data-registry-view="accounts"]').trigger('click');

        expect(wrapper.get('h1').text()).toBe('Accounts');
        expect(wrapper.findAll('.registry-row')).toHaveLength(1);
        expect(window.location.pathname).toBe('/accounts');
        expect(window.location.search).toBe('');
    });

    it('refreshes the accounts table when Inertia props update', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        await wrapper.get('[data-registry-view="accounts"]').trigger('click');
        expect(wrapper.findAll('.registry-row')).toHaveLength(1);

        await wrapper.setProps({
            accounts: [
                ...accounts,
                {
                    id: 99,
                    name: 'Example',
                    domain: 'example.com',
                    website: 'https://www.Example.COM/about',
                    notes: 'First durable account',
                    tags: ['saas'],
                    source: 'manual',
                    profile: null,
                    employee_count: null,
                    people_count: 0,
                    ready_count: 0,
                    readiness: 'No people',
                    campaigns_count: 0,
                    people: [],
                    updated_at: '2026-08-27T12:37:00Z',
                },
            ],
        });

        expect(wrapper.findAll('.registry-row')).toHaveLength(2);
        expect(wrapper.text()).toContain('Example');
        expect(wrapper.text()).toContain('example.com');
    });

    it('searches and filters the people registry', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        await wrapper.get('input[type="search"]').setValue('Orbital');
        expect(wrapper.findAll('.registry-row')).toHaveLength(1);
        expect(wrapper.get('.registry-row').text()).toContain('Sam Rivera');

        await wrapper.get('input[type="search"]').setValue('');
        await wrapper.get('.registry-filter-trigger').trigger('click');
        const interested = wrapper
            .findAll('.registry-filter-option')
            .find((option) => option.text().includes('Interested'))!;
        await interested.get('input').setValue(true);

        expect(wrapper.findAll('.registry-row')).toHaveLength(1);
        expect(wrapper.get('.registry-row').text()).toContain('Maya Chen');
    });

    it('opens the inspector above the registry and restores focus on Escape', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            attachTo: document.body,
            global: { stubs },
        });
        const trigger = wrapper.get('.row-entity-button');
        const table = wrapper.get('.registry-table-wrap').element;

        trigger.element.focus();
        await trigger.trigger('click');
        await flushPromises();

        const inspector = wrapper.get('.registry-inspector');
        expect(inspector.classes()).toContain('open');
        expect(inspector.text()).toContain('Maya Chen');
        expect(window.getComputedStyle(table).position).not.toBe('fixed');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(inspector.classes()).not.toContain('open');
        expect(document.activeElement).toBe(trigger.element);
        wrapper.unmount();
    });

    it('opens the new account dialog and submits the form', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        await wrapper.get('[data-registry-view="accounts"]').trigger('click');
        expect(
            wrapper.get('[data-test="export-accounts"]').attributes('href'),
        ).toBe('/accounts/export');

        await wrapper.get('[data-test="add-account"]').trigger('click');
        expect(wrapper.get('[role="dialog"]').text()).toContain(
            'Add a company',
        );

        form.name = 'Example';
        form.website = 'https://www.Example.COM/about';
        await flushPromises();
        await wrapper.get('.account-form-dialog').trigger('submit');

        expect(form.post).toHaveBeenCalledWith(
            '/accounts',
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
        expect(form.name).toBe('Example');
        expect(form.website).toBe('https://www.Example.COM/about');
    });

    it('offers create anyway when the name already exists', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        await wrapper.get('[data-registry-view="accounts"]').trigger('click');
        await wrapper.get('[data-test="add-account"]').trigger('click');

        form.name = 'Acme';
        form.errors = {
            name: 'Another account named ‘Acme’ already exists in this workspace.',
            confirmed_duplicate:
                'Create this account anyway to keep both records.',
        };
        await flushPromises();

        expect(wrapper.get('[role="dialog"]').text()).toContain(
            'Another account named ‘Acme’ already exists',
        );
        await wrapper
            .get('[data-test="confirm-duplicate-account"]')
            .trigger('click');

        expect(form.confirmed_duplicate).toBe(true);
        expect(form.post).toHaveBeenCalled();
    });

    it('asks for confirmation before deleting an account', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            attachTo: document.body,
            global: { stubs },
        });

        await wrapper.get('[data-registry-view="accounts"]').trigger('click');
        await wrapper.get('.row-entity-button').trigger('click');
        await flushPromises();

        expect(wrapper.get('.registry-inspector').text()).toContain(
            'Added manually',
        );
        await wrapper.get('[data-test="delete-account"]').trigger('click');
        expect(router.delete).not.toHaveBeenCalled();
        expect(wrapper.get('.registry-inspector').text()).toContain(
            'People keep their campaign history',
        );

        await wrapper
            .get('[data-test="confirm-delete-account"]')
            .trigger('click');
        expect(router.delete).toHaveBeenCalledWith(
            '/accounts/11',
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
        wrapper.unmount();
    });

    it('opens the new person dialog and submits the form', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        expect(
            wrapper.get('[data-test="export-people"]').attributes('href'),
        ).toBe('/people/export');

        await wrapper.get('[data-test="add-person"]').trigger('click');
        expect(wrapper.get('[role="dialog"]').text()).toContain(
            'Add someone to the registry',
        );

        form.email = 'fresh@acme.test';
        form.full_name = 'Fresh Person';
        await flushPromises();
        await wrapper.get('.person-form-dialog').trigger('submit');

        expect(form.post).toHaveBeenCalledWith(
            '/people',
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
    });

    it('shows a named duplicate-email error on the person form', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        await wrapper.get('[data-test="add-person"]').trigger('click');
        form.email = 'maya@northstar.studio';
        form.errors = {
            email: 'Another person, ‘Maya Chen’, already uses maya@northstar.studio.',
        };
        await flushPromises();

        expect(wrapper.get('[role="dialog"]').text()).toContain(
            'Another person, ‘Maya Chen’, already uses maya@northstar.studio.',
        );
    });

    it('asks for confirmation before deleting a person', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            attachTo: document.body,
            global: { stubs },
        });

        await wrapper.get('.row-entity-button').trigger('click');
        await flushPromises();

        expect(wrapper.get('.registry-inspector').text()).toContain(
            'Met at SaaStr',
        );
        expect(wrapper.get('.registry-inspector').text()).toContain('Founder');
        await wrapper.get('[data-test="delete-person"]').trigger('click');
        expect(router.delete).not.toHaveBeenCalled();
        expect(wrapper.get('.registry-inspector').text()).toContain(
            'Campaign history is preserved.',
        );

        await wrapper
            .get('[data-test="confirm-delete-person"]')
            .trigger('click');
        expect(router.delete).toHaveBeenCalledWith(
            '/people/1',
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
        wrapper.unmount();
    });

    it('reviews person import buckets before commit', async () => {
        state.page.props.flash.personImport = {
            id: 9,
            source_name: 'People import · september.csv',
            policy_summary:
                'Native CSV import · prospecting use · 365-day normalized retention · export allowed',
            mapping_version: 1,
            total: 5,
            new: 1,
            updated: 1,
            invalid: 1,
            duplicate: 1,
            suppressed: 1,
            samples: {
                new: [
                    {
                        row_number: 1,
                        email: 'new@northstar.studio',
                        name: 'New Person',
                        company: 'Northstar',
                        issue: 'new',
                    },
                ],
                updated: [],
                invalid: [],
                duplicate: [],
                suppressed: [
                    {
                        row_number: 5,
                        email: 'gone@acme.test',
                        name: 'Gone',
                        company: 'Acme',
                        issue: 'suppressed',
                    },
                ],
            },
        };

        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        await wrapper.get('[data-test="import-people"]').trigger('click');
        await flushPromises();

        expect(
            wrapper.get('[data-test="person-import-dialog"]').text(),
        ).toContain('Review import');
        expect(
            wrapper.get('[data-test="person-import-dialog"]').text(),
        ).toContain('People import · september.csv');
        expect(
            wrapper.get('[data-test="person-import-bucket-new"]').text(),
        ).toContain('1');
        expect(
            wrapper.get('[data-test="person-import-bucket-updated"]').text(),
        ).toContain('1');
        expect(
            wrapper.get('[data-test="person-import-bucket-invalid"]').text(),
        ).toContain('1');
        expect(
            wrapper.get('[data-test="person-import-bucket-duplicate"]').text(),
        ).toContain('1');
        expect(
            wrapper.get('[data-test="person-import-bucket-suppressed"]').text(),
        ).toContain('1');
        expect(
            wrapper.get('[data-test="person-import-dialog"]').text(),
        ).toContain('Suppressed people stay in the registry');

        await wrapper
            .get('[data-test="commit-person-import"]')
            .trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/people/imports/9/commit',
            { expected_mapping_version: 1 },
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
    });

    it('maps pasted CSV columns before previewing a person import', async () => {
        const wrapper = mount(PeopleAccountsIndex, {
            props: { people, accounts },
            global: { stubs },
        });

        await wrapper.get('[data-test="import-people"]').trigger('click');
        await wrapper
            .get('#person-import-paste')
            .setValue('email,name,company\nnew@acme.test,New Person,Acme');
        await wrapper
            .get('[data-test="continue-person-import"]')
            .trigger('click');

        expect(
            wrapper.get('[data-test="person-import-dialog"]').text(),
        ).toContain('Match your columns');
        await wrapper
            .get('[data-test="preview-person-import"]')
            .trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/people/imports/preview',
            expect.objectContaining({
                mapping: expect.objectContaining({ email: 'email' }),
            }),
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
    });
});
