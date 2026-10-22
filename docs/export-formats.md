# Export formats

All workspace exports are UTF-8 CSVs streamed from authenticated `GET` routes. Spreadsheet formula characters (`=`, `+`, `-`, `@`) are prefixed with `'` so cells stay data. Timestamps are ISO 8601 UTC (`toIso8601String()`). Tags and multi-source summary values are joined with `|`.

Exports never include unsubscribe tokens, tracking tokens, OAuth or provider credentials, message bodies, or send-attempt idempotency keys.

AI-provider credentials and credential versions are never exportable. The current CSV allow-lists also omit AI request fingerprints, provider request/generation IDs, reservations, usage-ledger rows, and audit events. Owners and members view safe, metadata-only AI usage inside **Workspace settings → AI & research → Activity**; adding an AI CSV export requires a separately reviewed allow-list.

Download them from **Workspace settings → Exports**, or from the Prospects toolbar for the two registry files. Source and integration exports are owner-only. Web export attempts write a bounded audit record containing workspace, actor, export type, optional source ID and timestamp; the audit never stores filenames, queries, provider bodies or exported personal content. CSV responses use `Cache-Control: no-store`.

The GTM profile export is the deliberate exception to the CSV formats below.
Workspace owners download it from the GTM profile overflow menu as UTF-8 JSON.
Members cannot export it. Unlike the paginated history screens, this is a
bounded operational snapshot so one request cannot grow without limit as
versions, sources, and review items accrue.

## GTM profile (`GET /settings/gtm-profile/export`)

The top-level document contains `format`, `schema_version`, `exported_at`,
`collections`, `workspace`, `profile`, `versions`, `sources`, and
`review_items`. `profile`
identifies the current immutable version and includes its visible canonical
payload. `versions` contains the 25 newest immutable versions with their visible
payload, hash, origin, actor label, timestamp, and current-version marker. `sources`
contains persisted safe URL/title/excerpt/hash metadata. `review_items`
contains both open and resolved proposals, their safe source references, and
resolution metadata. Sources and review items are each capped at 200 newest
records. Each `collections` entry reports `total`, `included`, `limit`, and
`truncated`, so an operator can see when older records were omitted. The
authoritative current payload is always present under `profile`, even when its
version is outside the recent-version window.

It does not include provider credentials, prompts, complete provider
responses, operation idempotency keys, or internal research checkpoints. The
route is workspace-scoped, owner-only, independently rate limited, and returns
`Cache-Control: no-store` with the attachment filename `gtm-profile.json`.

Offer lifecycle history is not included in this profile file because Offers
are separate aggregates. AF-02 exposes bounded, cursor-paginated Offer history
in the authenticated Offers UI; it does not claim a bulk Offer export format.

## Accounts (`GET /accounts/export`)

| Column         | Type             | Meaning                                    | Example                                |
| -------------- | ---------------- | ------------------------------------------ | -------------------------------------- |
| `name`         | string           | Account name                               | `Northstar Labs`                       |
| `website`      | string, nullable | Stored website                             | `https://northstar-labs.workflow.demo` |
| `domain`       | string, nullable | Normalised domain                          | `northstar-labs.workflow.demo`         |
| `notes`        | string, nullable | Free text                                  | `Design-led SaaS.`                     |
| `tags`         | string           | `\|`-joined tags                           | `saas\|design`                         |
| `source`       | string           | `manual`, `import`, or `campaign_backfill` | `manual`                               |
| `source_count` | integer          | Number of durable source memberships       | `2`                                    |
| `source_ids`   | string           | Policy-permitted source UUIDs, `\|`-joined | `uuid-a\|uuid-b`                       |
| `source_names` | string           | Policy-permitted source names, `\|`-joined | `Manual\|Partner list`                 |
| `latest_observed_at` | datetime, nullable | Newest permitted source observation | `2026-09-03T12:00:00+00:00`            |
| `source_omission_reason` | string, nullable | Why source detail was withheld | `restricted_by_source_policy`          |
| `people_count` | integer          | Durable people on this account             | `3`                                    |
| `created_at`   | datetime         | ISO 8601 UTC                               | `2026-08-28T11:00:00+00:00`            |
| `updated_at`   | datetime         | ISO 8601 UTC                               | `2026-08-28T11:00:00+00:00`            |

## People (`GET /people/export`)

| Column            | Type             | Meaning                                    | Example                              |
| ----------------- | ---------------- | ------------------------------------------ | ------------------------------------ |
| `name`            | string           | Display name                               | `Priya Shah`                         |
| `email`           | string           | Stored email                               | `priya@northstar-labs.workflow.demo` |
| `job_title`       | string, nullable | Title                                      | `Founder`                            |
| `account`         | string, nullable | Current account name                       | `Northstar Labs`                     |
| `notes`           | string, nullable | Free text                                  | `Met at SaaStr.`                     |
| `tags`            | string           | `\|`-joined tags                           | `saas`                               |
| `source`          | string           | `manual`, `import`, or `campaign_backfill` | `manual`                             |
| `source_count`    | integer          | Number of durable source memberships       | `2`                                  |
| `source_ids`      | string           | Policy-permitted source UUIDs, `\|`-joined | `uuid-a\|uuid-b`                     |
| `source_names`    | string           | Policy-permitted source names, `\|`-joined | `Manual\|Event list`                 |
| `latest_observed_at` | datetime, nullable | Newest permitted source observation | `2026-09-03T12:00:00+00:00`          |
| `source_omission_reason` | string, nullable | Why source detail was withheld | `restricted_by_source_policy`        |
| `eligibility`     | string           | `Eligible` or `Suppressed`                 | `Eligible`                           |
| `campaigns_count` | integer          | Campaign recipient rows for this person    | `1`                                  |
| `created_at`      | datetime         | ISO 8601 UTC                               | `2026-08-28T11:00:00+00:00`          |
| `updated_at`      | datetime         | ISO 8601 UTC                               | `2026-08-28T11:00:00+00:00`          |

The legacy `source` column remains a compatibility projection. The `source_*` columns are the authoritative S1 provenance summary. A source policy can omit IDs/names/timestamps while retaining `source_count` and an explicit omission reason.

## Prospect source summaries (`GET /exports/sources`)

Owner-only, one aggregate row per source. Columns are `source_id`, `source_name`, `kind`, `status`, `current_version_id`, `policy_hash`, `version_count`, `run_count`, `observation_count`, `account_membership_count`, `person_membership_count`, and `latest_observed_at`. This export contains aggregate operational metadata only; it excludes observed values, input keys, provider URLs, uploaded rows and provider payloads.

## Integration summaries (`GET /exports/integrations`)

Owner-only, one row per configured neutral integration connection. Columns are `provider`, `connection_status`, `registration_count`, `enabled_registration_count`, `operation_count`, `succeeded_operation_count`, `ambiguous_operation_count`, `reserved_cost_micros`, `actual_cost_micros`, `last_tested_at`, and `last_healthy_at`. Credential ciphertext/versions, idempotency keys, request fingerprints, queries and provider request/response bodies are never included.

## Complete permitted source lineage (`GET /prospect-sources/{source}/export`)

Owner-only and rejected with HTTP 403 when the current source policy prohibits export. The rectangular file contains `source`, `version`, `run`, `observation`, `account_membership`, and `person_membership` record types. Its columns are:

```text
record_type,source_id,source_name,source_kind,source_status,version_id,version,
schema_version,origin,policy_hash,policy,configuration_hash,run_id,run_state,
received,valid,accepted,created,linked,duplicate,conflicted,suppressed,rejected,
expired,observation_id,row_number,entity_type,disposition,match_method,account_id,
person_id,observed_values,omission_reason,membership_id,membership_state,
first_observed_at,last_observed_at,created_at,finished_at
```

`observed_values` contains only fields allowed by the source-version export policy. Expired/redacted/restricted observations keep their audit row but replace values with an `omission_reason`. Input keys, provider IDs/URLs, raw rows and raw provider results are not exported. The separate `GET /prospect-sources/{source}/runs/{run}/errors.csv` file exposes only row number, entity type, disposition, match method and bounded safe error code.

## Enrolment snapshots (`GET /exports/enrollments`)

One row per `campaign_recipients` row in the workspace. Identity columns are the values copied at enrolment, not the live person or account. Rows remain after the person or account is deleted (`person_id` / `account_id` may then be empty because those foreign keys null on delete).

| Column             | Type               | Meaning                                     | Example                              |
| ------------------ | ------------------ | ------------------------------------------- | ------------------------------------ |
| `campaign`         | string             | Campaign name                               | `Workflow verification`              |
| `campaign_status`  | string             | `draft`, `active`, `paused`, or `completed` | `draft`                              |
| `email`            | string             | Snapshot email                              | `priya@northstar-labs.workflow.demo` |
| `full_name`        | string, nullable   | Snapshot name                               | `Priya Shah`                         |
| `first_name`       | string, nullable   | Snapshot first name                         | `Priya`                              |
| `company`          | string, nullable   | Snapshot company                            | `Northstar Labs`                     |
| `email_domain`     | string, nullable   | Domain of the snapshot email                | `northstar-labs.workflow.demo`       |
| `person_id`        | integer, nullable  | Registry person id when still linked        | `12`                                 |
| `account_id`       | integer, nullable  | Registry account id when still linked       | `4`                                  |
| `mailbox`          | string, nullable   | Sending mailbox address                     | `david@outboss.test`                 |
| `sequence_version` | integer, nullable  | Sequence version number used at enrolment   | `1`                                  |
| `status`           | string             | Recipient lifecycle status                  | `queued`                             |
| `stop_reason`      | string, nullable   | Why sending stopped                         | `replied`                            |
| `enrolled_at`      | datetime           | Recipient `created_at`                      | `2026-08-28T11:00:00+00:00`          |
| `first_sent_at`    | datetime, nullable | First provider-reaching send                |                                      |
| `last_sent_at`     | datetime, nullable | Latest send                                 |                                      |
| `replied_at`       | datetime, nullable | First human-style reply                     |                                      |
| `stopped_at`       | datetime, nullable | Terminal stop                               |                                      |
| `paused_at`        | datetime, nullable | Per-enrolment pause                         |                                      |
| `removed_at`       | datetime, nullable | Removed from campaign                       |                                      |

## Suppressions (`GET /exports/suppressions`)

| Column       | Type     | Meaning                                   | Example                          |
| ------------ | -------- | ----------------------------------------- | -------------------------------- |
| `email`      | string   | Suppressed address                        | `lea@lumen-health.workflow.demo` |
| `reason`     | string   | `unsubscribe`, `hard_bounce`, or `manual` | `unsubscribe`                    |
| `created_at` | datetime | ISO 8601 UTC                              | `2026-08-28T11:00:00+00:00`      |

Internal `source_message_id` values are not exported.

## Campaign outcomes (`GET /exports/campaign-outcomes`)

Counts come from `CampaignOutcomeCounts` — the same service the campaign index uses.

| Column         | Type               | Meaning                                       | Example                     |
| -------------- | ------------------ | --------------------------------------------- | --------------------------- |
| `campaign`     | string             | Campaign name                                 | `Workflow verification`     |
| `status`       | string             | `draft`, `active`, `paused`, or `completed`   | `draft`                     |
| `created_at`   | datetime           | ISO 8601 UTC                                  | `2026-08-28T11:00:00+00:00` |
| `launched_at`  | datetime, nullable | First launch                                  |                             |
| `completed_at` | datetime, nullable | Completion                                    |                             |
| `queued`       | integer            | Recipients not yet sent                       | `8`                         |
| `sent`         | integer            | Recipients with `first_sent_at`               | `0`                         |
| `replied`      | integer            | Replied / interested / not now / wrong person | `0`                         |
| `interested`   | integer            | Marked interested                             | `0`                         |
| `bounced`      | integer            | Hard bounce                                   | `0`                         |
| `unsubscribed` | integer            | Unsubscribed                                  | `0`                         |
| `needs_review` | integer            | `manual_review` send attempts                 | `0`                         |
