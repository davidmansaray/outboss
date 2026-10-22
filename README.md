# Outboss

Outreach loses context when the person, the campaign and the reply live in different places. Outboss keeps them in one workspace: who someone is, what has already been said and what should happen next.

The product follows the work around an email. Prepare an audience, write the campaign, review exactly what will be sent, then handle replies and follow-ups from one inbox.

![Unified inbox with synthetic conversations](docs/screens/inbox.png)

## Keep the next step connected

A contact record is useful when it helps someone decide what to do. People and accounts connect to campaign activity and conversations, so a reply can be read alongside the outreach that prompted it.

That context also changes the plan. A human reply, a hard bounce or an opt-out stops later campaign messages for that person. Out-of-office messages and delivery issues have their own places in the inbox, rather than looking like another unanswered lead.

## Review before sending

Approval should refer to something concrete. The campaign review brings together the exact recipients, messages, sender identities and sending window. Approval and launch are separate steps; readiness checks show what still needs attention.

Sending has limits too: pacing, suppression and recovery rules sit behind the workflow. When a provider's delivery outcome is uncertain, the work goes to manual reconciliation rather than an automatic resend.

## Take a look around

The local demo uses synthetic records and fake mail providers, with campaign sending disabled. It lets you inspect the workflow without contacting anyone.

1. Open **People** and **Accounts** to see the audience and its context.
2. Open a campaign and follow the audience, message sequence, senders and schedule through to review.
3. Open the inbox to inspect conversations, replies, delivery issues and opt-outs.

## Under the interface

Laravel 13, Vue 3 and Inertia connect the application, with Gmail, Microsoft and Zoho adapters and workspace-scoped REST and MCP access.

Campaigns can be written and reviewed without an AI provider. AI and research are optional layers for evidence, objectives, offers and drafts, with scoped permissions, budgets, approvals and reconciliation. They build on the review workflow rather than making approval implicit.

This repository includes the application, synthetic fixtures, tests and generic deployment examples. Real OAuth connections, external AI/research and production delivery require separate configuration and verification; the local demo does not establish live-provider performance.

## Run locally

Requirements: PHP 8.4+, Composer 2, Node 22, npm and SQLite.

```bash
composer install
php scripts/setup-demo.php
php artisan migrate --force
php artisan db:seed --class=UserFlowBrowserSeeder --force
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8125 --no-reload
```

Open **http://127.0.0.1:8125** with the synthetic local account **david@outboss.test / password**. The setup helper creates a random application key and an isolated SQLite file without overwriting existing configuration.

## Architecture and evidence

- [Architecture and code map](docs/architecture.md)
- [Local demo and deployment boundary](docs/self-hosting.md)
- [REST API](docs/api.md), [OpenAPI specification](docs/api/openapi.yaml) and [MCP tools](docs/mcp.md)
- [OAuth setup](docs/oauth-setup.md), [export formats](docs/export-formats.md) and [operations](docs/operations.md)
- [Verification results](docs/verification.md) and [snapshot provenance](docs/provenance.md)

## Checks

```bash
php artisan wayfinder:generate --with-form
composer ci:check
npm run build
npx playwright install chromium
npm run test:browser:user-flows
```

The default browser suite uses its own disposable SQLite database on port 8124. CI runs checks and builds; it has no production deployment step.

## Licence

The existing MIT declaration in `composer.json` is preserved. See [provenance](docs/provenance.md).
