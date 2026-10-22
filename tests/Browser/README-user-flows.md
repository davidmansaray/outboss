# Operator user-flow browser suite

These Playwright journeys walk the sample-data workspace used to set up
campaigns, people, accounts, and sender identities. Nothing is sent to a real
mail provider.

Run `npm run build`, then:

```sh
npm run test:browser:user-flows
```

The harness starts `http://127.0.0.1:8014`, creates a disposable SQLite
database, and seeds `david@outboss.test` / `password` through
`UserFlowBrowserSeeder`. That seeder reuses the Unibox and workflow demo
fixtures: two fake-provider mailboxes, durable people and accounts,
suppressions, inbox threads, and the draft **Workflow verification** campaign.

`CAMPAIGN_SENDING_ENABLED=false` for the whole run. Tests may open launch
review, but they do not confirm a live send. Sequence test-send is hidden on
composition campaigns and is never clicked.

The server prints the disposable database path. Restarting the harness recreates
the database and requires a fresh sign-in. Keep the normal app on port 8000
separate from this fixture.

Covered journeys:

- Sign-in, failed credentials, and a new empty workspace
- Mailboxes: connected identities, connect actions, sync, disconnect cancel
- People and accounts: create, search, CSV import preview and commit
- Campaigns: list, create, enrol from the registry, write a sequence, sending setup
- Inbox, suppressions, exports, and primary navigation

This is deterministic product evidence only. It does not replace a real-provider
smoke pass.
