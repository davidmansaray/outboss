# Queue and operational model

Synchronisation, sending, campaigns, AI operations and objective execution use distinct queues. The generic Supervisor template in `deploy/supervisor-outboss.conf` uses `/srv/outboss/current`; replace that path and the process user for your installation.

Run the scheduler every minute and supervise the required workers. The objective worker's reservation and shutdown windows must exceed its execution timeout. Tests under `tests/Unit/Deploy` preserve those relationships.

The local walkthrough uses synchronous work, fake providers and disabled sending. Redis, OAuth, external AI/research and managed-mail infrastructure are separately configured integrations.

`scripts/deploy/release.php` demonstrates staged releases and activation. Preparation migrates and backfills before activation; the post-activation hook restarts workers. This application has no automatic production deployment step.

See [local setup](self-hosting.md), [API](api.md), [MCP](mcp.md) and [export formats](export-formats.md).
