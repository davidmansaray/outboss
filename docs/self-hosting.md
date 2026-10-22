# Local setup and deployment boundary

Use PHP 8.4+, Composer 2, Node 22 and npm. SQLite is for the local fixture; MySQL 8 is the durable deployment target.

Follow the commands in the [README](../README.md). `scripts/setup-demo.php` creates a random application key, a database inside this checkout, fake mail-provider bindings through the testing environment, an array mailer and disabled campaign sending. It does not overwrite configuration, migrate/reset databases, connect real mailboxes or require provider keys.

The synthetic fixture account is `david@outboss.test` with password `password`. Inspect the inbox, People, Accounts and the Workflow verification campaign, then review Workspace settings → API keys and the [REST API](api.md) / [MCP](mcp.md) surfaces.

## Validation

```bash
composer ci:check
npm run build
npx playwright install chromium
npm run test:browser:user-flows
```

Browser tests create a separate SQLite fixture under `storage/framework/testing` and serve it on port 8124. They do not use the normal demo database. `npm run test:browser` uses the same operator suite; the S2 configuration is an additional integration-specific surface.

## Real deployments

Supply your own environment, application key, database, Redis, TLS, OAuth registrations, queue supervision and backups. See [OAuth setup](oauth-setup.md) and [operations](operations.md). Do not expose the seeded demo account as a customer installation.

The source's operational controls remain intact. Sending, paid/external integrations and mailbox connections require their configuration and readiness checks. This fixture demonstrates application behaviour; it is not a live email-delivery or production-acceptance claim.
