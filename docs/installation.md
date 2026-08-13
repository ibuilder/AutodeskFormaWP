# Installation and operations

## Prerequisites

| Component | Requirement |
|---|---|
| WordPress | 6.4+, PHP 7.4+ |
| Backend | Node.js 22.6+ |
| Extension | Node.js 22.6+ for the build; any modern browser at runtime |
| Autodesk | An APS application with a registered callback URL |

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

### Data directory

`DATA_DIR` (default `./data`) holds encrypted tokens, job history and the published-entry index. It contains secrets — exclude it from backups that are less protected than the service itself, or move to a managed database by replacing `JsonStore`.

## 3. Forma extension

```bash
cd forma-extension
npm install
npm run build      # outputs to dist/
```

Host `dist/` over HTTPS and register it as an embedded view in your Forma extension manifest. On first load, open the **Connection** tab and enter the backend URL and `EXTENSION_API_KEY`.

For local development, `npm run dev` serves on port 5173 and the app runs outside Forma with manual hub/project entry.

## Publishing

1. **Content** — confirm the hub and project, choose snapshot or sync, pick the WordPress status.
2. **Build preview** — the backend reads Autodesk and returns the canonical payload. Nothing has been sent to WordPress yet.
3. **Preview** — check the title, metrics and assets.
4. **Publish** — the job is queued; **History** shows the outcome.

Republishing unchanged content is reported as *skipped*, not as an error.

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
| 429 `forma_publisher_rate_limited` | More than 60 requests/minute | Batch publishes, or raise via `forma_publisher_rate_limit` |
| 400 with schema issues | Payload violates the canonical schema | Compare against [canonical-schema.md](canonical-schema.md); check plugin and backend versions match |

**Forma → Publish Log** records every attempt with its result, connection and message. The backend's **History** tab shows the job side, including retry attempts.

### Upgrading

Roll out the **plugin first**, then the backend. The canonical schema rejects unknown properties, so a backend that sends a new field to an older plugin will be refused. Upgrading in this order avoids that window.

### Uninstalling

Deleting the plugin removes options, connections, capabilities, scheduled events and log entries. **Published projects and assets are kept**, so removing the plugin does not destroy editorial content. Delete those content types manually if you want them gone.
