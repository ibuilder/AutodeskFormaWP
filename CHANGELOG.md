# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

## [1.0.0]

Initial release of all three components.

### WordPress plugin

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

### Backend service

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

### Forma extension

- Embedded view with Content, Preview, Connection and History tabs.
- Canonical payload preview before anything is sent to WordPress.
- Publish, update and unpublish actions with job status feedback.
- Graceful degradation to manual project entry when run outside Forma.

[1.0.0]: https://github.com/ibuilder/forma-to-wordpress/releases/tag/v1.0.0
