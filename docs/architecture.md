# Architecture

## Why source-driven, not live

A tempting design is a WordPress plugin that queries Autodesk on page load. That approach fails on three counts:

1. **Credentials.** Autodesk Platform Services uses OAuth flows that return access and refresh tokens. Holding those in WordPress means holding them somewhere a compromised theme, plugin or admin session can reach.
2. **Availability.** A public marketing site would inherit Autodesk's availability and rate limits on every page view.
3. **Coupling.** Rendering directly from Autodesk response shapes means an upstream field rename becomes a broken public page.

So the flow is inverted. Forma is the *source environment* where a person selects content and triggers a publish. A backend service holds the credentials and does the talking. WordPress receives a finished, normalized snapshot and renders it locally.

## Components

### Forma embedded extension (`forma-extension/`)

A hosted web app loaded as an embedded view inside Forma. It reads the current project context from the Forma SDK and calls the backend. It holds no Autodesk credential and never contacts WordPress.

Tabs: **Content** (select what to publish), **Preview** (see the canonical payload before it is sent), **Connection** (backend URL and API key, Autodesk sign-in), **History** (job list with status and errors).

It authenticates to the backend with a shared `x-api-key`. Running outside Forma it degrades gracefully to manual hub/project entry, so it is developable in a plain browser tab.

### Backend service (`backend/`)

The only component that holds secrets.

| Responsibility | Implementation |
|---|---|
| APS OAuth | Authorization code + PKCE for user context, client credentials for service reads |
| Token storage | AES-256-GCM encrypted at rest, decrypted only in memory |
| Autodesk reads | Read-only Data Management calls (hubs, projects, folders, items) |
| Normalization | Transform to the versioned [canonical schema](canonical-schema.md) |
| Publishing | HMAC-signed requests to WordPress |
| Reliability | Bounded retries with exponential backoff and jitter; non-retryable 4xx fail fast |
| Idempotency | Stable content hash short-circuits no-op republishes before a request is sent |

### WordPress plugin (`wordpress-plugin/forma-publisher/`)

A receiver, renderer and local cache — deliberately not the integration brain.

- Signed ingest endpoint at `/wp-json/forma-publisher/v1/ingest`.
- Content types `forma_project` and `forma_asset`, plus a private `forma_log` type for the audit trail.
- Four blocks and four shortcodes, with theme template overrides.
- Custom capabilities so publishing, log access and settings are separately grantable.

The audit log is stored as posts of a private post type rather than in a custom table. That avoids `dbDelta`, schema migrations and direct SQL entirely, which is why the plugin passes Plugin Check's database checks without suppressions.

## Publish sequence

```
Forma extension          Backend                        WordPress
      │                     │                                │
      ├─ POST /api/preview ─▶                                │
      │                     ├─ APS: read project ───────────▶│ (Autodesk)
      │                     ├─ normalize → canonical         │
      ◀─ canonical payload ─┤                                │
      │                     │                                │
      ├─ POST /api/publish ─▶                                │
      │                     ├─ hash: unchanged? ─ skip       │
      │                     ├─ enqueue job                   │
      ◀─ 202 job queued ────┤                                │
      │                     ├─ sign(method, route, ts,       │
      │                     │       nonce, sha256(body))     │
      │                     ├─ POST /forma-publisher/v1/ingest ─▶
      │                     │                                ├─ verify signature
      │                     │                                ├─ reject stale / replayed
      │                     │                                ├─ validate schema
      │                     │                                ├─ upsert by source_id
      │                     │                                ├─ sync assets, prune stale
      │                     ◀─────────── 200 + result ───────┤─ write audit entry
      │                     ├─ record published entry        │
```

## Idempotency

Every project carries a stable `source_id` from Autodesk. WordPress matches on that meta key, so a repeat publish updates the same post rather than creating a duplicate.

Both sides also hash the canonical project:

- The **backend** compares against the last hash it published and skips the request entirely if nothing changed.
- The **plugin** compares against `_forma_payload_hash` on the post and returns `unchanged` without rewriting content.

The double check matters because the backend's record can be lost (fresh deployment, cleared data directory) while WordPress still holds the content.

## Asset lifecycle

Assets are matched by their own `source_id`. On each publish the plugin:

1. Upserts every asset in the payload.
2. Trashes any asset previously linked to that project but absent from the current payload.

Removal uses the trash rather than a permanent delete, so an upstream mistake is recoverable.

## Sync mode

Snapshot is the default. In sync mode the plugin retains the link and, on a WordPress cron schedule, sends a signed request to the backend's `/api/refresh` endpoint asking it to re-push.

The direction matters: WordPress never pulls from Autodesk. It only signals the backend, using the same HMAC scheme in reverse, so the shared secret remains the only credential either side needs.

For that refresh to work unattended, the backend has to be able to rebuild a project without the extension being open. It therefore stores a **source descriptor** — hub, project, optional proposal, whether files were included, and the template used — alongside each published entry. On refresh it walks every sync-mode entry, rebuilds it from Autodesk, re-applies the original template and enqueues an update.

Two consequences worth stating:

- A project published from a hand-built payload with no descriptor **cannot** be refreshed. Those are counted and logged rather than silently skipped.
- The refresh is queued asynchronously and returns `202` immediately, so a WordPress cron request never blocks on Autodesk.

Because the queue hashes content before sending, a refresh over unchanged projects costs one Autodesk read each and no WordPress writes at all.

## Publishing templates

A template is a pre-mapped view of a project, chosen in the extension. It decides which parts of the canonical payload reach WordPress: description, metrics, assets, thumbnail, and optionally a restriction to specific metric categories.

Templates are applied in two places on purpose:

1. In `/api/preview`, so the editor sees exactly what will be published.
2. In `/api/publish`, so a caller posting a hand-built project cannot bypass the constraint the template expresses.

They only remove or constrain content, never add it, which keeps them safe to re-apply. That matters for sync mode: the template is stored with the project and re-applied on every refresh, so a project published under `sustainability` cannot start exposing area metrics months later because upstream data changed.

## Extension points

| Hook | Type | Purpose |
|---|---|---|
| `forma_publisher_ingested` | action | Fires after a publish operation is applied |
| `forma_publisher_content_changed` | action | Fires when published content changes; used for cache invalidation |
| `forma_publisher_rate_limit` | filter | Adjust accepted requests per connection per minute |
| `forma_publisher_template_candidates` | filter | Add or reorder template search paths |
