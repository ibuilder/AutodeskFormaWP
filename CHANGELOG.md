# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0]

Prepares the plugin for WordPress.org submission.

### Changed

- **Renamed to Publisher for Autodesk Forma**, slug `publisher-for-autodesk-forma`.
  Guideline 17 forbids a slug that begins with someone else's trademark, and the
  previous slug began with *Forma*. The text domain follows the slug, so every
  translation call and `block.json` textdomain field changed and the `.pot` was
  regenerated.
- Block names, CSS classes, the REST namespace, option names, meta keys and the
  PHP namespace are unchanged. None of them are required to match the slug, and
  block names are written into saved post content — renaming them would break
  existing pages.
- The theme template override folder is now
  `your-theme/publisher-for-autodesk-forma/`. Existing overrides must move.

### Added

- An **External services** section in `readme.txt` stating that there is no
  third-party service, that every host contacted is one the administrator
  entered, and exactly what is transmitted.
- An FAQ entry disclaiming affiliation with Autodesk.
- The GPL text now ships inside the plugin directory.
- `Templates::output()`, which writes a template straight to the output buffer
  instead of capturing it into a string only to echo it.

### Fixed

- `get_the_post_thumbnail()` output is now passed through `wp_kses_post()`
  before echoing. Lossless for core-generated image markup.
- Block asset manifests (`index.asset.php`) carry an `ABSPATH` guard.
- The `Contributors` field named an account that does not exist on
  WordPress.org, which would have failed review. It is now `blackrebel`.

## [1.1.0]

### Added

- **Local edit protection.** Snapshot publishing has an inherent conflict: an
  editor improves a project page, and the next synchronization silently
  discards that work. The plugin now records the post's modification time at
  each sync, so a later divergence is recognised as a human edit. Three
  policies: hold the update for review (default), keep the local edits, or
  overwrite. Projects with no recorded time predate the feature and are treated
  as clean, so upgrading does not park every project at once.
- **Editorial review screen** listing updates held because the project was
  edited in WordPress, with apply and keep-local actions, alongside projects
  awaiting approval. A count badge appears in the menu when anything needs
  attention. Discarding an update records the local version as the agreed
  state, so the next update is not immediately held again for the same reason.
- **Optional approval step** so newly published projects arrive as pending
  review rather than going live immediately. Applies to new projects only;
  updates follow the conflict policy instead.
- **Operator overview screen** answering whether the pipeline is working:
  connection status, ingest endpoint, last accepted publish, scheduled refresh
  and next run, WP-Cron mode, the active local edit policy, counts and recent
  activity.
- **`GET /metrics`** on the backend, exposing Prometheus text for jobs by
  status, queue depth, published and sync-tracked counts, and seconds since the
  last success and failure. Requires the extension API key.
- **`GET /ready`**, a readiness probe that checks storage and the Autodesk
  session and returns 503 when the service cannot publish. `/health` remains a
  liveness probe that never fails on a dependency.
- **Deployment artifacts**: a multi-stage Dockerfile running as an
  unprivileged user with tini as PID 1, a Compose file including PostgreSQL,
  and a deployment guide covering reverse proxies, systemd hardening, Autodesk
  application setup, monitoring, upgrade order and backups.
- **A documentation site** at https://ibuilder.github.io/AutodeskFormaWP/,
  generated from the markdown in `docs/` so the published site cannot drift
  from the repository. Adds reference documentation for the REST API,
  configuration, rendering, hooks and editorial review.
- **A Forma extension manifest** ready to register as an embedded view.

### Fixed

- Applying a held update re-parked it immediately. The conflict is derived from
  the post modification time, which is still divergent at that moment, so
  clearing the hold alone was not enough; the check is now skipped explicitly
  for that one write.

## [1.0.0]

First release. Everything below shipped together; the fixes were found during
pre-release hardening rather than in an earlier public version.

### Security

- **A known key id could lock out the real backend.** Rate limiting was applied
  before signature verification and charged to the connection, so anyone who
  learned a key id could exhaust that connection's budget with forged requests
  and deny service to the genuine backend. Limiting is now split: failed
  authentication is charged to the origin address, and only verified requests
  count against the connection. Proven by a test that fires 25 forged requests
  and then asserts the real backend still succeeds.

### Fixed

- **Autodesk file size and MIME type were never resolved.** The Data Management
  client matched entries in the response's `included` array by item id, but
  those entries are version resources with different urns (`dm.lineage:…` for an
  item versus `fs.file:vf.…?version=1` for its version). Against real responses
  nothing ever matched, so every asset silently lost its size and MIME type and
  fell back to extension-only kind inference. The client now follows
  `relationships.tip` to the correct version. Caught by new contract tests
  against recorded Autodesk response shapes.
- **`JsonStore.read()` handed out its internal cache object**, so a caller that
  mutated the result would corrupt state that a later unrelated write would
  persist. Reads now return a copy, which also makes the file store and the
  PostgreSQL store behave identically. Caught by the shared storage contract
  test.
- **`uninstall.php` could fatal if included twice**, aborting an uninstall part
  way through and leaving a site with half its plugin data removed. The
  declarations are now guarded.

- **Changing the sync refresh interval had no effect.** `Scheduler::ensure_events()`
  checked only whether a cron event existed, not what its recurrence was, so
  switching from hourly to daily saved the setting while the event kept firing
  hourly indefinitely. The recurrence is now compared and the event rescheduled
  when it differs. Caught by a new regression test.

### Added

- Integration test suite for the WordPress plugin: 225 assertions run against a
  real WordPress install through WP-CLI, covering signature and replay handling,
  rate limiting, schema validation, idempotency, asset pruning, XSS
  neutralisation, the media allow list and its bypass attempts, scheduled
  events, log retention, capabilities and the uninstall routine. Exits non-zero
  on failure so it gates CI, and runs on PHP 7.4, 8.3 and 8.4.
- `tests/setup-wp.sh` provisions a throwaway WordPress with the SQLite drop-in,
  making the suite reproducible locally and in CI.
- Backend unit tests for the publish queue (retry, backoff ceiling, fail-fast on
  non-retryable responses, idempotency short-circuit, source retention) and for
  publishing templates.
- **Sync mode now actually refreshes.** The backend previously acknowledged a
  refresh request and did nothing. It now stores a source descriptor with each
  published project, rebuilds every sync-mode project from Autodesk on refresh,
  re-applies the original template and enqueues an update.
- **Publishing templates**: `full`, `summary`, `metrics`, `sustainability` and
  `downloads` presets that constrain which parts of a project reach WordPress.
  Applied in both preview and publish so a hand-built payload cannot bypass
  them, and re-applied on every sync refresh.
- **Hub and project browsing** in the Forma extension, replacing manual-only
  identifier entry. Manual entry is retained as a fallback.

- **PostgreSQL storage.** Setting `DATABASE_URL` replaces the JSON file store
  with a PostgreSQL one whose mutations take a row lock inside a transaction,
  making the service safe to run as more than one instance. Both stores satisfy
  the same contract test, including concurrency, and CI runs the suite against a
  real PostgreSQL service.
- **Multisite coverage.** A network suite proves per-site isolation of content
  and credentials, that a credential from one site is rejected on another, and
  that uninstall reaches every site while preserving published content.
- **Contract tests for the Autodesk client** against recorded Autodesk Platform
  Services response shapes, covering hubs, projects, top folders, folder
  contents, version linking, canonical assembly and error handling.
- **`npm run verify`**, a live verification command that exercises the whole
  pipeline against real credentials without publishing anything, and reports
  which stage works. Warns on clock drift and on assets that resolve no size or
  MIME type.
- **Release packaging.** `bin/build-zip.sh` builds an installable archive, and a
  release workflow refuses to publish when the tag does not match the plugin
  version, re-inspects the archive for stray development files and runs Plugin
  Check against it. A packaging suite asserts agreement between the plugin
  header, the readme stable tag, the declared PHP and WordPress minimums, the
  changelog entry and each block's declared version.

### Changed

- The backend and extension now require Node.js 22.6+. Node 20 reached end of
  life in April 2026 and cannot run the TypeScript test files.
- Rate limiting is now two tiered: `forma_publisher_unverified_rate_limit`
  (default 20 failed attempts per address per minute) and the existing
  `forma_publisher_rate_limit` (default 60 verified requests per connection per
  minute).

### Initial implementation

#### WordPress plugin

- Signed ingest endpoint at `/wp-json/forma-publisher/v1/ingest` with HMAC-SHA256
  verification, timestamp tolerance, nonce replay protection and per connection
  rate limiting.
- Signed `/status` and `/lookup` endpoints for backend reconciliation.
- `forma_project` and `forma_asset` content types with metrics, tags, statuses,
  location and source metadata; private `forma_log` type for the audit trail.
- Idempotent upserts matched on the Autodesk source ID, with payload hashing so
  an unchanged republish is recorded as skipped rather than rewriting content.
- Asset synchronization that prunes assets no longer present upstream.
- Four server-rendered blocks and four shortcodes, with theme template
  overrides.
- Dedicated capabilities for settings, log access and content editing.
- Optional remote media import, off by default and restricted to an explicit
  host allow list.
- Publish audit log with configurable retention and daily purge.
- Optional scheduled refresh requests to the backend for sync mode content.

#### Backend service

- Autodesk Platform Services OAuth: authorization code with PKCE for user
  context, client credentials for service reads, automatic refresh.
- AES-256-GCM encryption of tokens at rest.
- Read-only Data Management client for hubs, projects, folders and items.
- Canonical transform with known metric labels, units and categories, and
  inferred asset kinds.
- Publish queue with bounded retries, exponential backoff and jitter;
  non-retryable 4xx responses fail fast.
- Signed WordPress client that derives the signed route and the request URL from
  the same source so they cannot drift apart.
- Structured JSON logging with automatic redaction of secrets and tokens.

#### Forma extension

- Embedded view with Content, Preview, Connection and History tabs.
- Canonical payload preview before anything is sent to WordPress.
- Publish, update and unpublish actions with job status feedback.
- Graceful degradation to manual project entry when run outside Forma.

[1.0.0]: https://github.com/ibuilder/AutodeskFormaWP/releases/tag/v1.0.0
