# Installation and operations

## Prerequisites

| Component | Requirement |
|---|---|
| WordPress | 6.4+, PHP 7.4+ |
| Backend | Node.js 22.6+ |
| Extension | Node.js 22.6+ for the build; any modern browser at runtime |
| Autodesk | An APS application with a registered callback URL |
| Database | Optional. PostgreSQL 12+ if you run more than one backend instance. |

## 1. WordPress plugin

Copy `wordpress-plugin/forma-publisher` into `wp-content/plugins/` and activate it.

Activation registers the content types, grants capabilities to the administrator and editor roles, writes default settings and flushes rewrite rules.

### Create a connection

**Forma → Connections → Add a connection.** The key ID and shared secret are shown once, immediately after creation. Copy both.

For production, define the secret in `wp-config.php` instead, so it never reaches the database:

```php
define( 'FORMA_PUBLISHER_CONNECTIONS', array(
	'fp_yourkeyid' => 'your-shared-secret',
) );
```

Constant-defined connections override stored ones and appear as *Managed in code*.

### Settings worth reviewing

| Setting | Default | Notes |
|---|---|---|
| Default post status | `draft` | Deliberately conservative — nothing goes live without an editorial step until you change this |
| Require HTTPS | on | Loopback hosts are exempt |
| Timestamp tolerance | 300s | Lower is stricter; raise only if clocks genuinely drift |
| Remote media import | off | Enable only with an explicit host allow list |
| Log retention | 30 days | Purged daily by cron |

## 2. Backend service

```bash
cd backend
cp .env.example .env
npm install
npm run build
npm start
```

Generate the secrets:

```bash
openssl rand -hex 32   # ENCRYPTION_KEY
openssl rand -hex 24   # EXTENSION_API_KEY
```

Fill in `.env`:

| Variable | Value |
|---|---|
| `ENCRYPTION_KEY` | 32 bytes, hex or base64. Encrypts Autodesk tokens at rest. |
| `APS_CLIENT_ID` / `APS_CLIENT_SECRET` | From your APS application |
| `APS_CALLBACK_URL` | Must match the callback registered with APS exactly |
| `WORDPRESS_URL` | Site root, no trailing path |
| `WORDPRESS_KEY_ID` / `WORDPRESS_SECRET` | From Forma → Connections |
| `EXTENSION_API_KEY` | Shared with the extension |

The service refuses to start with an incomplete configuration and prints exactly which variables are wrong — a misconfigured deployment fails at boot rather than at the first publish.

### Connect an Autodesk account

Visit `http://localhost:3000/auth/login` and complete the Autodesk sign-in. Tokens are stored encrypted and refreshed automatically.

Verify:

```bash
curl http://localhost:3000/health
curl -H "x-api-key: $EXTENSION_API_KEY" http://localhost:3000/api/wordpress/status
```

The second call round-trips a signed request to WordPress and back. If it returns the plugin version, the two halves are talking.

### Storage

By default the service keeps encrypted tokens, job history and the published-entry index as JSON files under `DATA_DIR` (default `./data`). That directory contains secrets — exclude it from any backup less protected than the service itself.

The file store is only safe for a **single process**. To run more than one instance, set `DATABASE_URL` and the service switches to PostgreSQL, where each mutation takes a row lock inside a transaction:

```
DATABASE_URL=postgres://user:password@host:5432/forma
```

The table (`forma_documents`) is created automatically on first use. Creation is serialized with an advisory lock, so several instances can start simultaneously against an empty database without colliding. Append `?sslmode=require` for managed providers that require TLS.

There is no automatic migration between the two stores. Switching to PostgreSQL on an existing deployment starts from an empty history: re-authenticate with Autodesk, and republish anything you want tracked for sync. Published WordPress content is unaffected, because it lives in WordPress.

### Verifying the whole pipeline

```bash
npm run verify
```

This exercises every dependency in turn against your real credentials — configuration, Autodesk session, hub and project access, canonical payload assembly, and a signed round trip to WordPress — and reports which stage works. It publishes nothing and writes nothing to Autodesk. It also warns about clock drift and about assets that resolve no file size or MIME type, both of which are entitlement dependent.

## 3. Forma extension

```bash
cd forma-extension
npm install
npm run build      # outputs to dist/
```

Host `dist/` over HTTPS and register it as an embedded view in your Forma extension manifest. On first load, open the **Connection** tab and enter the backend URL and `EXTENSION_API_KEY`.

For local development, `npm run dev` serves on port 5173 and the app runs outside Forma with manual hub/project entry.

## Publishing

1. **Content** — click **Browse Autodesk hubs** and pick a hub and project, or type the identifiers manually. Choose a publishing template, snapshot or sync, and the WordPress status.
2. **Build preview** — the backend reads Autodesk, applies the template and returns the canonical payload. Nothing has been sent to WordPress yet.
3. **Preview** — check the title, metrics and assets.
4. **Publish** — the job is queued; **History** shows the outcome.

Republishing unchanged content is reported as *skipped*, not as an error.

### Publishing templates

| Template | Publishes |
|---|---|
| Full project page | Description, every metric, all assets and the thumbnail |
| Summary only | Title, summary and thumbnail |
| Metrics report | Summary plus the metric table, no downloads |
| Sustainability highlights | Only environment and carbon metrics |
| Downloads only | Summary plus the asset list, no metrics |

The template is stored with the project and re-applied on every scheduled sync refresh, so an unattended refresh cannot widen what a page exposes. It is also enforced server side on publish, so a caller cannot bypass it by posting a hand-built payload.

## Operations

### Scheduled refresh

For sync-mode content, set **Forma → Settings → Refresh interval** and choose a signing connection. WordPress will then send a signed request to `POST {backend}/api/refresh` on that schedule.

WordPress cron only fires on site traffic. On a low-traffic site, replace it with a real cron:

```php
define( 'DISABLE_WP_CRON', true );
```

```
*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null
```

### Diagnosing a failed publish

| Symptom | Cause | Fix |
|---|---|---|
| 401 `forma_publisher_unknown_connection` | Key ID mismatch, or the connection is disabled | Check `WORDPRESS_KEY_ID` against Forma → Connections |
| 401 `forma_publisher_invalid_signature` | Wrong secret, or a proxy is rewriting the body | Re-copy the secret; confirm nothing re-encodes the request body |
| 401 `forma_publisher_stale_request` | Clock skew | Sync clocks with NTP; raise the tolerance only as a last resort |
| 401 `forma_publisher_https_required` | Plain HTTP to a non-loopback host | Use HTTPS, or disable the requirement for local testing only |
| 409 `forma_publisher_replayed_request` | The same nonce arrived twice | Usually a duplicate retry; safe to ignore |
| 429 `forma_publisher_rate_limited` | More than 60 **verified** requests/minute for one connection | Batch publishes, or raise via `forma_publisher_rate_limit` |
| 429 `forma_publisher_unverified_rate_limited` | More than 20 **failed** authentications/minute from one address | Usually a wrong secret being retried, or someone probing the endpoint. Check the secret first. |
| 400 with schema issues | Payload violates the canonical schema | Compare against [canonical-schema.md](canonical-schema.md); check plugin and backend versions match |
| 400 `unknown_template` | The backend was sent a template id that does not exist | Reload the extension so it refetches the template list |

### Sync refresh reports nothing to do

A sync-mode project can only be refreshed unattended if the backend stored a **source descriptor** for it — the hub, project and template it was published from. Projects published before this was recorded, or published from a hand-built payload, are counted and logged as skipped. Republish them once from the extension to attach a descriptor.

**Forma → Publish Log** records every attempt with its result, connection and message. The backend's **History** tab shows the job side, including retry attempts.

### Upgrading

Roll out the **plugin first**, then the backend. The canonical schema rejects unknown properties, so a backend that sends a new field to an older plugin will be refused. Upgrading in this order avoids that window.

### Uninstalling

Deleting the plugin removes options, connections, capabilities, scheduled events and log entries. **Published projects and assets are kept**, so removing the plugin does not destroy editorial content. Delete those content types manually if you want them gone.
