# Security model

## Trust boundaries

| Boundary | Credential | Direction |
|---|---|---|
| Extension → Backend | `EXTENSION_API_KEY` (shared, `x-api-key` header) | Outbound from the browser |
| Backend → Autodesk | APS OAuth access / refresh tokens | Server to server |
| Backend → WordPress | Connection shared secret (HMAC-SHA256) | Server to server |
| WordPress → Backend | The same connection secret, reversed | Server to server, cron only |

**WordPress never holds an Autodesk credential.** That is the single most important property of the design, and it holds by construction: the plugin has no Autodesk client, no token storage and no code path that contacts `developer.api.autodesk.com`.

## Request signing

Both directions use the same scheme. The canonical string is:

```
METHOD \n ROUTE \n TIMESTAMP \n NONCE \n sha256hex( RAW_BODY )
```

Signed with HMAC-SHA256 using the connection secret, sent as:

| Header | Contents |
|---|---|
| `X-Forma-Key` | Connection key ID |
| `X-Forma-Timestamp` | Unix timestamp |
| `X-Forma-Nonce` | Unique per request, 8–128 characters |
| `X-Forma-Signature` | `sha256=<hex digest>` |

`ROUTE` is the REST route **without** the `/wp-json` prefix — that is what `WP_REST_Request::get_route()` reports on the receiving side. Both the URL and the signed route are derived from the same value in `WordPressClient`, so they cannot drift apart.

Including the method, the route and a hash of the raw body means a captured signature cannot be moved to a different endpoint or reused with altered content.

### Why the lookup endpoint takes a POST body

Autodesk identifiers are URNs containing colons. Placing one in a path segment forces percent-encoding, and the client and server can then disagree about the exact route string being signed — a subtle way to make valid requests fail and to create an encoding-dependent verification path. The lookup endpoint therefore takes `source_id` in the request body, which the body hash already covers.

## Replay and abuse resistance

| Control | Behaviour |
|---|---|
| Timestamp tolerance | Configurable, default 300s. Outside the window, the request is rejected as stale. |
| Nonce store | Each nonce is remembered for twice the tolerance. A repeat returns HTTP 409. |
| Rate limit | 60 requests per connection per minute by default, filterable via `forma_publisher_rate_limit`. |
| HTTPS enforcement | On by default; loopback hosts are exempt so local development still works. |
| Constant-time comparison | `hash_equals` in PHP, `timingSafeEqual` in Node. |
| Unknown-key handling | An unknown key ID still performs a comparable HMAC computation, so response timing does not confirm whether a key exists. |

## Input handling

Payloads are validated **before** they reach any write path, using a JSON Schema registered with the REST API:

- `additionalProperties: false` on the project object, so unrecognised fields are rejected rather than silently stored.
- Length caps on every string, item caps on every array, and range caps on latitude, longitude and precision.
- `operation` and `status` are closed enums.

After validation, values are still sanitized per field: `wp_kses_post` for body content, `sanitize_text_field` for scalars, `esc_url_raw` for URLs, `sanitize_mime_type` for MIME types. Output is escaped at render time in every template.

The backend applies its own defence in depth: upstream text is HTML-escaped during normalization, so markup from Autodesk is inert before WordPress ever sees it.

## Server-side request forgery

The only outbound fetch the plugin can be told to make is a featured image download. It is guarded by:

1. **Off by default.** `allow_media_import` must be explicitly enabled.
2. **Host allow list.** The URL host must appear in `media_allowed_hosts`. There is no wildcard.
3. **WordPress HTTP safety.** Downloads use `download_url()`, which routes through `wp_safe_remote_get()` and blocks internal and loopback addresses.

With the setting off — the default — a payload containing a hostile image URL is recorded as skipped and no request is made.

## Secret storage

Connection secrets can be defined in `wp-config.php`, which keeps them out of the database entirely:

```php
define( 'FORMA_PUBLISHER_CONNECTIONS', array( 'fp_yourkeyid' => 'your-shared-secret' ) );
```

Constant-defined connections take precedence over stored ones and are shown in the admin as *Managed in code* with no edit or delete controls.

Database-stored secrets are generated with `wp_generate_password( 64, true, true )` and displayed exactly once, immediately after creation, via a short-lived per-user transient.

On the backend, Autodesk tokens are encrypted with AES-256-GCM before being written to disk. The log formatter redacts any key named like a secret, token, password, signature or API key, so credentials cannot leak through diagnostics.

## Authorization in WordPress

The plugin adds dedicated capabilities rather than reusing `manage_options`:

| Capability | Granted to | Controls |
|---|---|---|
| `forma_manage_settings` | Administrator | Settings and connections |
| `forma_view_logs` | Administrator, Editor | The publish audit trail |
| `edit_forma_projects` and related | Administrator, Editor | Project and asset content |

Every admin screen checks its capability before rendering, every form is nonce-protected, and every state-changing action runs through `admin-post.php` with `check_admin_referer`.

Front-end views respect visibility: a project that is not published renders only for a user who can read it, and diagnostic notices ("that project could not be found") are shown only to users who can edit projects. Visitors get nothing.

## Audit trail

Every publish, update, unpublish, archive, delete and connection change is recorded with its operation, result, connection key ID, job ID, source ID, affected post and message. Entries are retained for a configurable number of days and purged by a daily cron event.

Deliberately **not** recorded: IP addresses, user agents and payload bodies. The log answers "what changed, when, via which connection" without becoming a personal-data store or a place where secrets accumulate.

## Reporting a vulnerability

Please open a private security advisory on the repository rather than a public issue.
