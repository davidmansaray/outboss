# Verification

Checked locally on 22 October 2026 using PHP 8.5.3, Node 22.23.1 and the committed dependency locks. CI configuration is supplied but has not yet run on GitHub.

| Check | Result |
|---|---|
| Composer locked install and manifest validation | Passed |
| npm locked install | Passed |
| ESLint, Prettier and Vue TypeScript | Passed |
| Vue unit tests | 54 files, 288 tests passed |
| PHP Pint and PHPStan | Passed; zero static-analysis errors |
| PHP test suite | Initial run: 1,546 passed, 2 skipped; 10 fixture-related failures/errors resolved, with all 13 tests in the affected files passing on recheck |
| Production frontend build | Passed |
| Operator browser journeys | 18 passed initially; the remaining journey passed after updating an obsolete mailbox selector |
| Documented local setup and login | Passed with synthetic fixtures and fake mail providers |
| Setup helper repeat run | Preserved the existing configuration, with file mode 0600 |

The browser suite uses an isolated SQLite database on port 8124. The README demo uses a separate SQLite file on port 8125. Campaign sending is disabled in both.

Four required message-drafting evaluation JSON fixtures were restored after the first run exposed their removal. The 22 cases are synthetic/non-customer and use `.invalid` URLs. They retain their runtime hashes and pending release-gate metadata; deterministic responses explicitly provide no target-provider evidence.

The seeded-campaign test now checks the selected sender checkboxes directly. It previously searched for an old “Selected inboxes” control that is absent from this source snapshot; no application behaviour was changed for that correction.

## Publication checks

Gitleaks 8.30.1 scanned the staged release files with no credential findings. The private source history was also scanned; its sole finding was a documentation idempotency key rather than an authentication secret. That example is replaced with an obvious demo identifier here. A manual tracked-content check found no local user paths or production host wiring.

These checks cover the local fixture application. They do not establish production deployment, live OAuth delivery, live model quality or external-provider acceptance.
