# Isolated S3 browser fixture

Run `npm run build`, then `bash tests/Browser/s3-server.sh` from the repository
root. Open `http://127.0.0.1:8013` and sign in as
`s3-browser@outboss.test` with password `password`.

The server prints its disposable database path and an Execution Plan ID. Open
`/execution-plans/<id>` to review that exact plan. Approve it, prepare its
Campaigns, and launch them as separate actions. Approval and preparation must
leave both Message and Send Attempt counts at zero.

This harness creates a fresh temporary SQLite database and never migrates or
resets the normal local database. It uses the testing environment, deterministic
AI adapters, and `FakeMailProvider`. Its fixed clock is Monday 2026-09-07 at
10:00 UTC, keeping approval expiry and business-hour checks repeatable. These
controls live only in the test harness, not in product rollout flags.
Sessions also use the disposable database. File-session modification times follow
the real clock and can appear expired against the fixture's fixed clock, causing
a successful login to redirect straight back to the login form.

To test expiry, place one UTC ISO timestamp (for example
`2026-09-07T10:16:00+00:00`) in `clock.txt` alongside the exact printed disposable
database. Subsequent browser requests and driver commands use it; without that
file they use the fixed Monday 10:00 default. This control is restricted to the
testing database and does not change the normal application's clock.

Inspect the fixture using the exact printed database path:

```sh
php tests/Browser/s3-drive.php /exact/printed/path/s3-browser.sqlite proof
php tests/Browser/s3-drive.php /exact/printed/path/s3-browser.sqlite send
php tests/Browser/s3-drive.php /exact/printed/path/s3-browser.sqlite reply
php tests/Browser/s3-drive.php /exact/printed/path/s3-browser.sqlite conversion
```

The `send` operation runs the existing scheduler, sender and Exposure projector,
including repeated calls to verify replay safety. It must produce one sent
attempt and one Exposure for the one-action fixture. Repeating the command
must report zero additional fake sends. The optional `launch` operation invokes
the same governed launch service for a previously approved and prepared fixture;
it does not substitute for the browser launch-button check.

`reply` adds one idempotent synthetic human reply and runs the normal immediate
reply-stop path, followed by its Conversation projection. `conversion` submits
one manual Account event through normal intake and rebuilds attribution/progress.
The complete fixture must show one Exposure, one Conversation, a `human_reply`
stop reason, one Conversion Event and one attributed outcome. Repeating all
three operations must not add messages, sends, conversations or conversions.

For normal operator-driven recovery, the product command is
`php artisan odg-s3:reconcile-outcomes --workspace=<id> --limit=100 --json`.
It repairs execution, Conversation and attribution projections without making
provider calls or sending mail. The scheduler runs the same bounded repairs
every minute with an overlap lock; workers must consume the `objectives` queue.

Stop the server with Ctrl+C. The temporary database is retained for inspection.
Restarting creates a new database and requires a fresh browser sign-in. Keep the
normal app tab on `http://outboss.test:8080` separate from this fixture.

This is deterministic implementation evidence only. It does not establish live
provider acceptance, owner approval of evaluation thresholds, or deployment
readiness. The thin loop is only a checkpoint before the completed slice's
regression, migration, fresh-context review and live-release gates.
