# Connect Outboss to your AI agent

Outboss exposes an [MCP](https://modelcontextprotocol.io/) server so Claude, Cursor, ChatGPT, n8n, and other agents can inspect and change the authenticated workspace — campaigns, people, the unified inbox, mailboxes, and suppressions — using the same scopes as the REST API.

Create a key in **Workspace settings → API keys**, then point the client at `https://<your-host>/mcp`. After you create a key, the page also shows a copy-paste MCP snippet with the token filled in.

The HTTP API that these tools wrap is documented in [docs/api.md](api.md) and [docs/api/openapi.yaml](api/openapi.yaml).

## What the server exposes

Server name `Outboss`, version `0.3.0`, registered in `app/Mcp/OutbossServer.php`.

- **33 tools** — list/get/create/update for campaigns, people, accounts, threads, mailboxes, and suppressions, plus launch, resume, reply, compose, and test send.
- **2 resources** — campaign outcome counts and mailbox health.
- **2 prompts** — campaign review before launch, and Unibox reply triage.

Tools whose descriptions start with **Sends real email:** send mail or start campaign sending. Confirm with the user before calling them.

### Tools

Tool names are kebab-case class names (Laravel MCP default).

| Tool | Scope | Notes |
| --- | --- | --- |
| `list-campaigns` | `campaigns:read` | Optional `status`. Defaults to 20 results (maximum 100). |
| `get-campaign` | `campaigns:read` | One campaign plus outcome counts. |
| `get-campaign-launch-readiness` | `campaigns:read` | Check before `launch-campaign`. |
| `list-campaign-recipients` | `campaigns:read` | Optional lifecycle `status`. |
| `create-campaign` | `campaigns:write` | Draft with a default Intro step. Does not send. |
| `update-campaign` | `campaigns:write` | Name, send mode, mailboxes, open tracking. |
| `add-sequence-step` | `campaigns:write` | Campaign must be draft or paused. |
| `update-sequence-step` | `campaigns:write` | Delay, `send_as`, subject, body. |
| `reorder-sequence-steps` | `campaigns:write` | Every active step id exactly once. |
| `enroll-people` | `campaigns:write` | Maximum 500 person ids. Does not send. |
| `pause-campaign` | `campaigns:write` | Stops further sequence mail. |
| `complete-campaign` | `campaigns:write` | Draft campaigns cannot be completed. |
| `update-recipient` | `campaigns:write` | Pause, resume, or pin a mailbox. |
| `launch-campaign` | `campaigns:launch` | **Sends real email.** Confirm first. Check readiness. |
| `resume-campaign` | `campaigns:launch` | **Sends real email.** Confirm first. |
| `list-people` | `people:read` | Search name, email, or account. |
| `get-person` | `people:read` | Includes linked account when present. |
| `list-accounts` | `people:read` | Search name, domain, website. |
| `create-person` | `people:write` | Unique email. Does not enrol. |
| `update-person` | `people:write` | Email required and must stay unique. |
| `create-account` | `people:write` | Unique website domain. |
| `list-threads` | `inbox:read` | Folders, classification, mailbox, campaign. No bodies. |
| `get-thread` | `inbox:read` | Messages (text only). Does not mark read. |
| `reply-to-thread` | `inbox:send` | **Sends real email.** Confirm first. |
| `compose-message` | `inbox:send` | **Sends real email.** Confirm first. |
| `send-test-step` | `inbox:send` | **Sends real email.** Confirm first. |
| `mark-thread-done` | `inbox:send` | Does not send email. |
| `classify-thread` | `inbox:send` | Triage only; does not send email. |
| `list-mailboxes` | `mailboxes:read` | Status, sync, daily cap, pause state. |
| `get-mailbox-auth-check` | `mailboxes:read` | SPF, DKIM, DMARC. |
| `list-suppressions` | `suppressions:read` | Newest first. |
| `add-suppression` | `suppressions:write` | Default reason `manual`. |
| `remove-suppression` | `suppressions:write` | Address can be enrolled again. |

List tools accept `page` and `per_page` (default 20, maximum 100).

### Resources

| URI | Scope | Description |
| --- | --- | --- |
| `outboss://campaigns/outcomes` | `campaigns:read` | Queued, sent, replied, interested, bounced, unsubscribed, and needs-review counts. Capped at 100 campaigns. |
| `outboss://mailboxes/health` | `mailboxes:read` | Connection status, sync health, and sending pause state. |

### Prompts

| Prompt | Scope | Description |
| --- | --- | --- |
| `campaign-review` | `campaigns:read` | Review sequence, audience, mailboxes, and launch readiness. Argument: `campaign_id`. Do not launch until the user confirms. |
| `triage-replies` | `inbox:read` | Summarise threads that need attention and suggest `classify-thread` actions. Do not classify until the user confirms. |

## Endpoint and authentication

HTTP MCP (Streamable HTTP) is registered at `/mcp` with Sanctum authentication:

```text
https://<your-host>/mcp
```

Use the same personal access token as the REST API:

```http
Authorization: Bearer {token}
```

The token's scopes gate every tool, resource, and prompt. A missing scope returns an MCP error: `Missing required scope: {scope}`.

Local stdio (same machine as the app) is registered as handle `outboss`:

```bash
php artisan mcp:start outboss
```

Remote agents should use the HTTP endpoint and a scoped API key. Stdio is for a local checkout where the artisan process can reach the application database.

## Client setup

Replace `https://<your-host>` and `YOUR_API_KEY` (or paste the snippet from Workspace settings → API keys after creating a key).

### Claude Desktop

Add a server to the Claude Desktop MCP config (`claude_desktop_config.json`: macOS `~/Library/Application Support/Claude/`, Windows `%APPDATA%\Claude\`).

```json
{
  "mcpServers": {
    "outboss": {
      "url": "https://<your-host>/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}
```

If your Claude Desktop build expects a command instead of a remote URL, spawn the local server from an Outboss checkout:

```json
{
  "mcpServers": {
    "outboss": {
      "command": "php",
      "args": ["artisan", "mcp:start", "outboss"],
      "cwd": "/path/to/outboss"
    }
  }
}
```

Restart Claude Desktop after saving.

### Claude Code

Project file `.mcp.json`, or add the server from the CLI:

```bash
claude mcp add --transport http outboss https://<your-host>/mcp \
  --header "Authorization: Bearer YOUR_API_KEY"
```

Equivalent `.mcp.json`:

```json
{
  "mcpServers": {
    "outboss": {
      "type": "http",
      "url": "https://<your-host>/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}
```

Local stdio:

```bash
claude mcp add --transport stdio outboss -- php artisan mcp:start outboss
```

Run that from the Outboss project directory (or pass the full path to `artisan`).

### Cursor

Project file `.cursor/mcp.json`, or the user file `~/.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "outboss": {
      "url": "https://<your-host>/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}
```

Reload MCP servers from Cursor Settings → MCP after saving. The same JSON is shown on the API keys page when a new token is created.

### ChatGPT (connectors)

In ChatGPT, open **Settings → Connectors** (or **Apps & connectors**, depending on the build) and add a custom MCP server:

1. Name: `Outboss`
2. Server URL: `https://<your-host>/mcp`
3. Authentication: bearer token / API key
4. Token: the personal access token from Workspace settings → API keys

The host must be reachable from ChatGPT (a public HTTPS URL). Localhost will not work unless you use a tunnel you trust.

Enable the connector on the conversation before asking it to review a campaign or triage replies.

### n8n

Use the **MCP Client** node (or an HTTP Request node posting to `/mcp`):

1. Transport: HTTP / Streamable HTTP
2. Server URL: `https://<your-host>/mcp`
3. Additional header: `Authorization` = `Bearer YOUR_API_KEY`

Give the n8n credential only the scopes that workflow needs. A read-only analyst key is enough for reporting; do not attach `campaigns:launch` or `inbox:send` unless the workflow is meant to send mail.

## Recommended scope sets

Create keys from Workspace settings → API keys. The **Read-only** button selects every `:read` scope.

| Key | Scopes | Use |
| --- | --- | --- |
| Read-only analyst | `campaigns:read`, `people:read`, `inbox:read`, `mailboxes:read`, `suppressions:read`, `exports:read` | Reporting, research, campaign review, triage suggestions. Cannot mutate or send. |
| No-send drafting | All `:read` plus `campaigns:write`, `people:write`, `mailboxes:write`, `suppressions:write` | Draft campaigns, edit sequences, enrol people, manage suppressions. Cannot launch, resume, reply, compose, or test-send. |
| Full operator | Every scope, including `campaigns:launch` and `inbox:send` | Agents that may send mail after an explicit user confirmation. |

Start with the analyst key. Add write scopes when the agent must edit records. Add launch and send only when you intend the agent to send email.

`exports:read` is unused by the current MCP tools (exports are REST-only) but is part of the read-only set so the same key works for both MCP and `GET /api/v1/exports/*`.

## Safety

- Tools that send email or start sending are prefixed **Sends real email:** — `launch-campaign`, `resume-campaign`, `reply-to-thread`, `compose-message`, `send-test-step`. Confirm with the user before calling them.
- `campaigns:launch` and `inbox:send` are distinct from write scopes. A drafting key cannot launch or send even if the agent asks to.
- Always call `get-campaign-launch-readiness` (or the `campaign-review` prompt) before `launch-campaign`. Do not launch while blockers remain.
- `enroll-people`, `pause-campaign`, `complete-campaign`, `mark-thread-done`, and `classify-thread` change workspace data but do not send mail.
- The server stays inside the authenticated workspace. Do not assume access to another workspace's records.
- Use British English in user-facing summaries (the server instructions already ask the model to do this).
