# Outboss API

REST API v1 for campaigns, people, the unified inbox, mailboxes, suppressions, and exports. Every token is permanently scoped to the workspace where it was created; switching workspaces in the browser does not retarget an existing token.

The machine-readable spec is [docs/api/openapi.yaml](api/openapi.yaml) (OpenAPI 3.1). Column-level export shapes are in [docs/export-formats.md](export-formats.md). Connecting an AI agent over MCP is in [docs/mcp.md](mcp.md).

Base URL:

```text
https://<your-host>/api/v1
```

Replace `<your-host>` with the hostname of your Outboss install.

## Authentication

Create a personal access token in **Workspace settings → API keys**. Choose only the scopes the integration needs. The full token is shown once — copy it immediately.

Send it as a Sanctum bearer token:

```http
Authorization: Bearer {token}
Accept: application/json
```

```bash
curl -sS "https://<your-host>/api/v1/campaigns" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Accept: application/json"
```

A missing or invalid token returns HTTP 401:

```json
{
    "error": {
        "type": "unauthenticated",
        "message": "Authentication required."
    }
}
```

A token that lacks the route's scope returns HTTP 403:

```json
{
    "error": {
        "type": "insufficient_scope",
        "message": "This API key does not have the campaigns:read scope."
    }
}
```

## Scopes

Launch and send are separate from write so a key can draft campaigns, edit sequences, and manage people without sending email.

| Scope                | Label              | Description                                |
| -------------------- | ------------------ | ------------------------------------------ |
| `campaigns:read`     | Read campaigns     | View campaigns, sequences, and recipients. |
| `campaigns:write`    | Write campaigns    | Create and edit campaigns and sequences.   |
| `campaigns:launch`   | Launch campaigns   | Launch and resume campaign sending.        |
| `people:read`        | Read people        | View people and accounts.                  |
| `people:write`       | Write people       | Create and edit people and accounts.       |
| `inbox:read`         | Read inbox         | View threads and messages.                 |
| `inbox:send`         | Send from inbox    | Reply to threads and compose messages.     |
| `mailboxes:read`     | Read mailboxes     | View connected mailboxes.                  |
| `mailboxes:write`    | Write mailboxes    | Connect, update, and disconnect mailboxes. |
| `suppressions:read`  | Read suppressions  | View the suppression list.                 |
| `suppressions:write` | Write suppressions | Add or remove suppression entries.         |
| `exports:read`       | Read exports       | Download workspace exports.                |

`campaigns:write` covers create, edit, pause, complete, enrol, and sequence changes. It does **not** launch or resume sending — that is `campaigns:launch`. Test sends, replies, and compose require `inbox:send`.

Each operation in the OpenAPI spec documents its required scope in the description and the `x-required-scope` extension.

## Rate limits

Authenticated API routes use the `api` limiter: **120 requests per minute** per personal access token (falling back to the user or client IP when no token is present). The public `GET /api/v1/server` uses the same limit per client IP. Exceeding the limit returns HTTP 429:

```json
{
    "error": {
        "type": "rate_limited",
        "message": "Too many requests."
    }
}
```

## Pagination

List endpoints return Laravel's paginator wrapper:

```json
{
    "data": [],
    "links": {
        "first": "https://<your-host>/api/v1/campaigns?page=1",
        "last": "https://<your-host>/api/v1/campaigns?page=3",
        "prev": null,
        "next": "https://<your-host>/api/v1/campaigns?page=2"
    },
    "meta": {
        "current_page": 1,
        "last_page": 3,
        "per_page": 15,
        "total": 42
    }
}
```

Pass `page` and `per_page`. `per_page` is clamped to 1–100. Defaults:

| Endpoint                                                         | Default `per_page` |
| ---------------------------------------------------------------- | -----------------: |
| Campaigns, recipients, people, accounts, mailboxes, suppressions |                 15 |
| Enrolable people                                                 |                 25 |
| Threads                                                          |                 50 |

Resource collections also include Laravel's extra meta (`from`, `to`, `path`, page `links`). Enrolable-people and thread lists use a slimmer `meta` object.

## Errors

All `/api/*` errors use this envelope:

```json
{
    "error": {
        "type": "validation_error",
        "message": "The name field is required.",
        "errors": {
            "name": ["The name field is required."]
        }
    }
}
```

| HTTP | `error.type`                | When                                                            |
| ---: | --------------------------- | --------------------------------------------------------------- |
|  401 | `unauthenticated`           | Missing or invalid bearer token                                 |
|  403 | `insufficient_scope`        | Token lacks the route scope                                     |
|  403 | `forbidden`                 | Authorisation failed for the resource                           |
|  404 | `not_found`                 | Unknown id or workspace mismatch                                |
|  409 | `idempotency_conflict`      | An idempotency key was reused for a different target or payload |
|  409 | `client_operation_conflict` | A send operation id was reused for a different message          |
|  422 | `validation_error`          | Invalid body or query; `errors` lists fields                    |
|  429 | `rate_limited`              | More than 120 requests in a minute                              |
|  400 | `bad_request`               | Other HTTP 400                                                  |
|    * | `http_error`                | Other HTTP exceptions                                           |

## Idempotency

These routes accept an optional `Idempotency-Key` header (maximum 255 characters):

- `POST /campaigns/{campaign}/enrollments`
- `POST /campaigns/{campaign}/launch`
- `POST /threads/{thread}/reply`
- `POST /messages`
- `POST /sequence-steps/{step}/test`

When the header is present, the first non-5xx response is stored for 24 hours and replayed for the same token, route name, key, target, and request body. Reusing the key with a different target or payload returns `409 idempotency_conflict`. Omit the header (or send a blank value) to skip replay. Keys longer than 255 characters return a validation error.

Send-path bodies also require `client_operation_id` (UUID). That is send-attempt idempotency inside the mail engine — use both when you retry a send.

```bash
curl -sS -X POST "https://<your-host>/api/v1/campaigns/12/launch" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Accept: application/json" \
  -H "Idempotency-Key: demo-request-001"
```

---

## Campaigns

Required scopes: `campaigns:read` to list and inspect; `campaigns:write` to create, edit, pause, and complete; `campaigns:launch` to launch or resume.

### List and create

```bash
curl -sS "https://<your-host>/api/v1/campaigns?status=draft&per_page=15" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X POST "https://<your-host>/api/v1/campaigns" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"name":"Q3 founders"}'
```

Create returns HTTP 201 with a draft campaign and a default Intro step. List items are summaries (`people_count`, `steps_count`, outcome counts). `GET /campaigns/{id}` returns the full campaign, including `steps` and `mailbox_ids`.

### Update sending setup

```bash
curl -sS -X PATCH "https://<your-host>/api/v1/campaigns/12" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Q3 founders",
    "send_mode": "rotate",
    "mailbox_ids": [3, 4],
    "open_tracking_enabled": false
  }'
```

`send_mode` is `rotate` or `pin`. Sending setup can change only while the campaign is draft or paused. Unknown mailbox ids are ignored.

### Launch flow

1. Create the campaign (`campaigns:write`).
2. Attach mailboxes and set send mode.
3. Add or edit sequence steps (see below).
4. Enrol people (`POST /campaigns/{id}/enrollments`).
5. Check readiness (`GET /campaigns/{id}/launch-readiness`).
6. Launch (`POST /campaigns/{id}/launch`) with `campaigns:launch`.

```bash
curl -sS "https://<your-host>/api/v1/campaigns/12/launch-readiness" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

```json
{
    "ready": false,
    "blockers": [
        {
            "id": "no_mailboxes",
            "section": "sending",
            "label": "Sending",
            "message": "Attach at least one connected mailbox."
        }
    ]
}
```

`ready` is true only when `blockers` is empty. Do not launch while blockers remain.

```bash
curl -sS -X POST "https://<your-host>/api/v1/campaigns/12/launch" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Idempotency-Key: demo-request-001"

curl -sS -X POST "https://<your-host>/api/v1/campaigns/12/pause" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X POST "https://<your-host>/api/v1/campaigns/12/resume" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X POST "https://<your-host>/api/v1/campaigns/12/complete" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

Pause and complete use `campaigns:write`. Resume uses `campaigns:launch` and re-checks readiness.

---

## Sequence steps

Required scopes: `campaigns:read` for preview; `campaigns:write` to add, update, reorder, or delete; `inbox:send` for a test send.

```bash
curl -sS -X POST "https://<your-host>/api/v1/campaigns/12/steps" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title":"Follow-up"}'

curl -sS -X PATCH "https://<your-host>/api/v1/sequence-steps/44" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Intro",
    "delay_minutes": 0,
    "send_as": "new_thread",
    "subject_template": "Quick question, {{first_name}}",
    "body_template": "Hi {{first_name}} — noticed {{company}}…"
  }'

curl -sS -X PUT "https://<your-host>/api/v1/campaigns/12/steps/order" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"step_ids":[44,45]}'

curl -sS -X DELETE "https://<your-host>/api/v1/sequence-steps/45" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

Allowed `delay_minutes`: `0`, `2880` (2 days), `4320` (3 days), `5760` (4 days), `7200` (5 days), `10080` (7 days). `send_as` is `new_thread` or `reply`. Templates may use `{{first_name}}` and `{{company}}`. Use `{first phrase|second phrase|third phrase}` in either the subject or body for deterministic per-recipient variations. Outboss keeps each recipient's choice stable across previews, retries, and sending. Escape literal spintax delimiters as `\{`, `\|`, or `\}`. Existing templates keep their pre-variation literal behavior until their subject or body is edited and saved. The campaign must be draft or paused.

Preview (sample Jane / Acme recipient unless `recipient_id` is set):

```bash
curl -sS "https://<your-host>/api/v1/sequence-steps/44/preview?recipient_id=88" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

Test send (`inbox:send` — this sends real email):

```bash
curl -sS -X POST "https://<your-host>/api/v1/sequence-steps/44/test" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: demo-request-001" \
  -d '{
    "mailbox_id": 3,
    "to": "you@your-company.com",
    "client_operation_id": "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"
  }'
```

---

## Enrolments and recipients

Required scopes: `campaigns:read` to list; `campaigns:write` to enrol, pause, pin, or remove.

### Enrolable people

```bash
curl -sS "https://<your-host>/api/v1/campaigns/12/enrollable-people?only_eligible=1&query=maya" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

Each row includes `enrollable`, `eligibility` (`Eligible` or `Suppressed`), and `reason` (`Already in campaign`, `Suppressed`, or null).

### Enrol

```bash
curl -sS -X POST "https://<your-host>/api/v1/campaigns/12/enrollments" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: demo-request-001" \
  -d '{"person_ids":[101,102,103]}'
```

```json
{
    "enrolled": 2,
    "skipped": [
        { "person_id": 103, "name": "Lea Chen", "reason": "Suppressed" }
    ]
}
```

Maximum 500 distinct person ids per call. Enrolment does not send email. If the campaign is already completed and at least one person is enrolled, the response includes `notice` telling you to resume.

### Recipients

```bash
curl -sS "https://<your-host>/api/v1/campaigns/12/recipients?status=queued" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X PATCH "https://<your-host>/api/v1/campaign-recipients/88" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"action":"pause"}'

curl -sS -X PATCH "https://<your-host>/api/v1/campaign-recipients/88" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"mailbox_id": 4}'

curl -sS -X DELETE "https://<your-host>/api/v1/campaign-recipients/88" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

Provide exactly one of `action` (`pause` or `resume`) or `mailbox_id`. Mailbox cannot change after the first send. Recipient `status` is a display value (`in_sequence`, `opened`, or a lifecycle value); `lifecycle` is the stored status (`queued`, `active`, `paused`, `replied`, and so on).

---

## People and accounts

Required scopes: `people:read` / `people:write`. Both resources share these scopes.

```bash
curl -sS "https://<your-host>/api/v1/people?query=maya&account_id=7" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X POST "https://<your-host>/api/v1/people" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "maya@stripe.com",
    "full_name": "Maya Shah",
    "job_title": "Founder",
    "account_id": 7,
    "tags": ["saas"]
  }'

curl -sS -X PATCH "https://<your-host>/api/v1/people/101" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"email":"maya@stripe.com","full_name":"Maya Shah"}'

curl -sS -X DELETE "https://<your-host>/api/v1/people/101" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

`query` and `q` are aliases. Email must be unique in the workspace. `PATCH` requires `email`. Creating a person does not enrol them.

```bash
curl -sS "https://<your-host>/api/v1/accounts?q=stripe" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X POST "https://<your-host>/api/v1/accounts" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Stripe",
    "website": "https://stripe.com",
    "confirmed_duplicate": false
  }'
```

A website domain must be unique. If the name already exists and you are not supplying a website, set `confirmed_duplicate` to true.

---

## Threads and messages

Required scopes: `inbox:read` to list and show; `inbox:send` to reply, compose, mark done, classify, or send a test step.

```bash
curl -sS "https://<your-host>/api/v1/threads?folder=needs&mailbox=3&campaign=12&per_page=20" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS "https://<your-host>/api/v1/threads/55" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

`folder` values: `leads`, `all_replies`, `closed`, `needs`, `all_open` (default), `open`, `ooo`, `delivery`, `opt_outs`, `problems`, `done`, `sent`. `campaign` is a campaign id, `all`, or `none`. `q` searches subject, preview and the other person's name or email (2+ characters). `unread=1` returns only unread threads. Showing a thread does not change its read state.

Add `pagination=cursor` for keyset paging that stays correct while new replies arrive. The response has `meta.next_cursor` instead of page links; pass it back as `cursor`:

```bash
curl -sS "https://<your-host>/api/v1/threads?folder=needs&pagination=cursor&per_page=50" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

Folder totals, unread counts and the iOS badge come from one call:

```bash
curl -sS "https://<your-host>/api/v1/inbox/counts?mailbox=3" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

To keep a local copy in sync, ask for changes since a watermark. Call without `since` first to get one, then keep passing `meta.next_since` (repeat while `meta.has_more` is true). Rows can repeat across calls because the feed re-reads a short overlap; keep the one with the latest `updated_at`. `visible: false` means the thread left the inbox. `410 sync_reset_required` means reload from the first page.

```bash
curl -sS "https://<your-host>/api/v1/threads/changes?since=$WATERMARK" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

Thread summaries link the other side to the people registry when they can: `person` is `{"id", "name", "title"}` (`title` is the person's job title, or `null`) and `account` is `{"id", "name"}`; either is `null` when there is no linked record.

Mark a thread read or unread with `POST /threads/{id}/read` and `POST /threads/{id}/unread` (`inbox:send`).

Reply and compose send real email:

```bash
curl -sS -X POST "https://<your-host>/api/v1/threads/55/reply" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: demo-request-001" \
  -d '{
    "body": "Thanks — Thursday at 10:00 works.",
    "client_operation_id": "11111111-2222-3333-4444-555555555555"
  }'

curl -sS -X POST "https://<your-host>/api/v1/messages" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: demo-request-001" \
  -d '{
    "mailbox_id": 3,
    "to": "maya@stripe.com",
    "subject": "Intro",
    "body": "Hi Maya…",
    "client_operation_id": "66666666-7777-8888-9999-000000000000"
  }'
```

Triage does not send email:

```bash
curl -sS -X POST "https://<your-host>/api/v1/threads/55/done" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X POST "https://<your-host>/api/v1/threads/55/classification" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"action":"interested"}'
```

Classification actions: `interested`, `replied`, `treat_as_reply`, `not_now`, `wrong_person`, `remove_bad_address`, `remove_from_campaign`.

Reply and compose responses keep `sent`, `thread_id` and `send_attempt_id`, and also include `data.thread` (the updated thread summary) and `data.send_attempt` (`id`, `status`).

---

## iOS app sign-in

The Outboss iOS app does not use API keys. It signs in through the web, then holds short-lived tokens.

Before sign-in the app checks the address it was given with the public `GET /api/v1/server` (no token; 120 requests per minute per client address):

```json
{
    "data": {
        "product": "outboss",
        "version": "dev",
        "api": "v1",
        "push": false,
        "sign_in_url": "https://<your-host>/mobile/authorize"
    }
}
```

`version` is `OUTBOSS_VERSION`, or `dev` when it is not set. `push` is the same check as `capabilities.push` on `/me`. `sign_in_url` is where step 1 starts.

1. The app opens `GET /mobile/authorize` (a web page, not under `/api`) in a secure browser sheet with `client=ios`, `redirect_uri=outboss://auth/callback`, a PKCE `code_challenge` (S256), `state` and `device_name`. Outboss signs the person in as usual (password, two-factor or passkey), then shows **Choose a workspace**: one row per workspace. Tapping a row continues; **Cancel** returns `error=access_denied`.
   The page is skipped only when the person has exactly one workspace **and** signed in for this request within the last two minutes (the sheet sent them to the login page and they just completed it). Someone who was already signed in to the browser always sees the page, so another app cannot silently collect a code from an existing session.
2. Outboss redirects to `outboss://auth/callback?code=…&state=…` (or `?error=access_denied&state=…`). The code works once and expires after 60 seconds.
3. The app calls `POST /api/v1/auth/token` with `grant_type=authorization_code`, the code, its `code_verifier` and device details. It receives an access token (one hour) and a refresh token (single use, 60 days).
4. When the access token expires, requests return `401 token_expired`. The app calls `POST /api/v1/auth/token` with `grant_type=refresh_token`, and optionally `workspace_id` to switch workspace.

Device access tokens can only read and reply to the inbox and read mailboxes, campaigns and people. They are listed under **Settings → Signed-in devices**, where they can be signed out, and never appear under API keys. Reusing a spent refresh token more than 30 seconds later signs the device out (`session_revoked`).

`GET /api/v1/me` works with any token and returns the user, the token's workspace, every workspace they can switch to, and `capabilities.push`. `PUT /api/v1/devices/current/push` registers the phone's APNs token (`notify`: `needs`, `leads` or `off`); `POST /api/v1/auth/logout` signs the phone out.

Extra redirect URIs for development builds can be allowed with `OUTBOSS_MOBILE_REDIRECT_URIS` (comma-separated, exact match).

---

## Mailboxes

Required scopes: `mailboxes:read` / `mailboxes:write`.

```bash
curl -sS "https://<your-host>/api/v1/mailboxes" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS "https://<your-host>/api/v1/mailboxes/3/auth-check" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X POST "https://<your-host>/api/v1/mailboxes/3/sync" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X PATCH "https://<your-host>/api/v1/mailboxes/3/sending" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"daily_send_cap": 25}'

curl -sS -X POST "https://<your-host>/api/v1/mailboxes/3/resume-sending" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

`auth-check` returns `domain`, `spf`, `dmarc`, and `dkim` (`pass`, `missing`, or `unknown`). Sync queues a background job and returns `{ "queued": true, "data": { …mailbox } }`. `daily_send_cap` is 1–1000, or `null` to clear it. Connecting a mailbox still happens in the UI (OAuth).

---

## Suppressions

Required scopes: `suppressions:read` / `suppressions:write`.

```bash
curl -sS "https://<your-host>/api/v1/suppressions" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS -X POST "https://<your-host>/api/v1/suppressions" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"email":"lea@example.com","reason":"manual"}'

curl -sS -X DELETE "https://<your-host>/api/v1/suppressions/9" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"
```

`reason` is `unsubscribe`, `hard_bounce`, or `manual` (default). Re-adding an existing address returns HTTP 200 with the existing entry; a new entry returns 201.

---

## Exports

Required scope: `exports:read`. JSON by default. Request CSV with `?format=csv` or `Accept: text/csv`.

```bash
curl -sS "https://<your-host>/api/v1/exports/enrollments" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY"

curl -sS "https://<your-host>/api/v1/exports/suppressions?format=csv" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -o suppressions.csv

curl -sS "https://<your-host>/api/v1/exports/campaign-outcomes" \
  -H "Authorization: Bearer $OUTBOSS_API_KEY" \
  -H "Accept: text/csv" \
  -o campaign-outcomes.csv
```

JSON responses contain at most 1,000 records and include `meta.truncated`; request CSV for a complete, streaming export. They use the same columns as the CSV. See [docs/export-formats.md](export-formats.md) for column meanings.
