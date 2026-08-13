# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

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
