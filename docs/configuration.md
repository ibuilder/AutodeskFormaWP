# Configuration reference

Every setting in the WordPress plugin and every environment variable in the backend, with its default and what it actually affects.

## WordPress plugin settings

Found under **Forma → Settings**. Stored in the `forma_publisher_settings` option.

### Publishing

| Setting | Default | Effect |
|---|---|---|
| Default post status | `draft` | Used when a payload does not specify a status. Deliberately conservative: nothing goes live without an editorial step until you change it. |
| Editorial approval | off | When on, newly published projects arrive as `pending` rather than `publish`. Updates to existing projects are unaffected. |
| When a project was edited here | Hold for review | What happens when a project changed in WordPress after the last sync. See [Editorial review](editorial-review.md). |

### Security

| Setting | Default | Effect |
|---|---|---|
| Require HTTPS | on | Rejects publish requests not sent over TLS. Loopback hosts are exempt so local development works. |
| Timestamp tolerance | 300s | How far a request's timestamp may be from server time. Clamped to 30–3600. Lower is stricter. |

### Media

| Setting | Default | Effect |
|---|---|---|
| Remote media import | off | Whether featured images referenced in payloads are downloaded. **This is the only outbound request the plugin can be told to make.** |
| Allowed media hosts | empty | Exact host names permitted for image download. No wildcards. With the list empty and import on, every image is skipped. |

### Synchronization

| Setting | Default | Effect |
|---|---|---|
| Backend service URL | empty | Base URL used for scheduled refresh requests. |
| Refresh interval | Disabled | `hourly`, `twicedaily` or `daily`. Changing it reschedules the cron event. |
| Signing connection | none | Which connection secret signs outbound refresh requests. Required for refresh to run. |

### Audit log

| Setting | Default | Effect |
|---|---|---|
| Logging | on | Records every publish, update, unpublish, archive and connection change. |
| Retention | 30 days | Entries older than this are purged daily. Clamped to 1–365. |

## WordPress constants

Set in `wp-config.php`, before the "stop editing" line.

### `FORMA_PUBLISHER_CONNECTIONS`

Defines connections in code so secrets never reach the database. Constant-defined connections override stored ones and appear in the admin as *Managed in code* with no edit or delete controls.

```php
define( 'FORMA_PUBLISHER_CONNECTIONS', array(
	'fp_yourkeyid' => 'your-shared-secret',
) );
```

### `DISABLE_WP_CRON`

Not a plugin constant, but it matters here. WordPress cron only fires on site traffic, so a low-traffic site refreshes late. Disable it and drive `wp-cron.php` from a real scheduler:

```php
define( 'DISABLE_WP_CRON', true );
```

```
*/5 * * * * curl -s 'https://example.com/wp-cron.php?doing_wp_cron' > /dev/null
```

## Backend environment variables

Copy `backend/.env.example` to `backend/.env`. The service validates everything at startup and refuses to boot with an incomplete configuration, printing exactly which variables are wrong — a misconfigured deployment fails immediately rather than at the first publish.

### Required

| Variable | Notes |
|---|---|
| `ENCRYPTION_KEY` | 32 bytes, hex or base64. Encrypts Autodesk tokens at rest. Generate with `openssl rand -hex 32`. **Rotating it invalidates stored sessions.** |
| `APS_CLIENT_ID` | From your Autodesk Platform Services application. |
| `APS_CLIENT_SECRET` | As above. |
| `APS_CALLBACK_URL` | Must match the callback registered with APS **exactly**, including scheme and trailing path. |
| `WORDPRESS_URL` | Site root, no trailing path. |
| `WORDPRESS_KEY_ID` | From **Forma → Connections**. |
| `WORDPRESS_SECRET` | The shared secret shown once when the connection was created. |
| `EXTENSION_API_KEY` | Presented by the Forma extension as `x-api-key`. Generate with `openssl rand -hex 24`. |

### Optional

| Variable | Default | Notes |
|---|---|---|
| `NODE_ENV` | `development` | `development`, `test` or `production`. |
| `PORT` | `3000` | HTTP listen port. |
| `LOG_LEVEL` | `info` | `debug`, `info`, `warn` or `error`. Secrets are redacted at every level. |
| `DATA_DIR` | `./data` | JSON store location. **Contains secrets.** Ignored when `DATABASE_URL` is set. |
| `DATABASE_URL` | unset | PostgreSQL connection string. Setting it makes the service safe to run as more than one instance. Append `?sslmode=require` for managed providers. |
| `APS_BASE_URL` | `https://developer.api.autodesk.com` | Override only for testing against a mock. |
| `APS_SCOPES` | `data:read account:read` | Read-only by design. The service never writes to Autodesk. |
| `WORDPRESS_REST_PREFIX` | `/wp-json` | Change only if your site serves the REST API elsewhere. |
| `JOB_MAX_ATTEMPTS` | `5` | Attempts before a job is marked failed. Non-retryable 4xx responses fail on the first attempt regardless. |
| `JOB_BASE_DELAY_MS` | `1000` | Base for exponential backoff, with jitter. |
| `HTTP_TIMEOUT_MS` | `30000` | Applies to Autodesk and WordPress requests alike. |

## Choosing a storage backend

| | JSON file store | PostgreSQL |
|---|---|---|
| Enabled by | default | setting `DATABASE_URL` |
| Safe for multiple instances | **no** | yes |
| Setup | none | a database |
| Suitable for | a single process | anything horizontally scaled |

Both satisfy the same contract test, including a concurrency case asserting no update is lost. There is **no automatic migration** between them: switching starts from an empty history, so re-authenticate with Autodesk and republish anything you want tracked for sync. Published WordPress content is unaffected, because it lives in WordPress.
