# Autodesk Forma to WordPress Publisher

A source-driven publishing workflow that takes content from **Autodesk Forma** and publishes it into **WordPress** as curated, snapshot-friendly project pages.

This is deliberately *not* a WordPress plugin that queries Autodesk on every page request. Forma is the source environment where a user selects content, previews the output and triggers a publish; a trusted backend owns every Autodesk credential; WordPress is a receiver, renderer and local cache.

---

## Architecture

```
┌───────────────────────┐      ┌───────────────────────┐      ┌───────────────────────┐
│  Forma embedded view  │      │   Backend service     │      │  WordPress plugin     │
│  (forma-extension)    │─────▶│   (backend)           │─────▶│  (wordpress-plugin)   │
│                       │ API  │                       │ HMAC │                       │
│ • select content      │ key  │ • APS OAuth + tokens  │signed│ • verify signature    │
│ • preview output      │      │ • Autodesk API reads  │      │ • upsert projects     │
│ • publish / unpublish │      │ • canonical transform │      │ • render blocks       │
│ • job history         │      │ • job queue + retries │      │ • audit log           │
└───────────────────────┘      └───────────────────────┘      └───────────────────────┘
```

Three properties fall out of this split:

- **Autodesk tokens never reach a browser or WordPress.** They live only in the backend, encrypted at rest with AES-256-GCM.
- **The public site has no runtime dependency on Autodesk.** An upstream outage or an expired credential cannot break a published page.
- **WordPress is never bound to Autodesk response shapes.** The backend normalizes everything into a versioned [canonical schema](docs/canonical-schema.md) first.

| Component | Directory | Stack |
|---|---|---|
| Forma embedded extension | [`forma-extension/`](forma-extension) | TypeScript, Vite, `forma-embedded-view-sdk` |
| Backend service | [`backend/`](backend) | Node 22+, TypeScript, Express, Zod |
| WordPress plugin | [`wordpress-plugin/forma-publisher/`](wordpress-plugin/forma-publisher) | PHP 7.4+, WordPress 6.4+ |

## Status

| Check | Result |
|---|---|
| WordPress Plugin Check (all categories, severity 1, experimental) | No errors, no warnings |
| WordPress Coding Standards (WPCS 3, `WordPress` ruleset) | Clean |
| PHP compatibility | 7.4 – 8.4 |
| Plugin integration suite (single site) | 228 assertions passing |
| Plugin integration suite (multisite network) | 251 assertions passing |
| Backend tests | 62 passing |
| Storage contract (JSON file store and PostgreSQL) | Both pass the same contract |
| Cross-language signature interop (Node signer ↔ PHP verifier) | Byte-identical |

The plugin suite runs against a real WordPress install rather than mocks, so REST dispatch, capabilities, cron, the object cache and the uninstall routine all behave as they do in production. It covers signature and replay handling, rate limiting, schema validation, idempotency, asset pruning, XSS neutralisation, the media allow list (including bypass attempts), scheduled events, log retention and uninstall. On a network install it additionally proves per-site isolation of content and credentials, and that uninstall reaches every site.

The Autodesk client is covered by contract tests against recorded Autodesk Platform Services response shapes — JSON:API, with file versions carried in `included` and linked through `relationships.tip`. To validate against your own tenant, see [live verification](#live-verification).

## Quick start

### 1. WordPress plugin

Copy `wordpress-plugin/forma-publisher` into `wp-content/plugins/` and activate it. Then:

1. Go to **Forma → Connections** and create a connection.
2. Copy the key ID and shared secret. **The secret is shown once.**
3. Note the ingest URL on **Forma → Settings**, normally `https://your-site/wp-json/forma-publisher/v1/ingest`.

For production, prefer defining the secret in `wp-config.php` so it never touches the database:

```php
define( 'FORMA_PUBLISHER_CONNECTIONS', array( 'fp_yourkeyid' => 'your-shared-secret' ) );
```

### 2. Backend service

```bash
cd backend
cp .env.example .env      # fill in APS + WordPress credentials
npm install
npm run build
npm start
```

Generate the two secrets it needs:

```bash
openssl rand -hex 32
```

Then connect an Autodesk account by visiting `http://localhost:3000/auth/login`.

### 3. Forma extension

```bash
cd forma-extension
npm install
npm run dev
```

Host the built output and register it as an embedded view in your Forma extension manifest. On the **Connection** tab, enter the backend URL and the `EXTENSION_API_KEY` value.

## Publishing modes

| Mode | Behaviour |
|---|---|
| **Snapshot** (default) | Content is copied into WordPress at publish time. Fast, editorially stable, no live dependency on Autodesk. |
| **Sync** | A source-to-target link is retained. WordPress asks the backend to re-push on a schedule, and the backend rebuilds each project from Autodesk unattended. |

Snapshot is the default deliberately: it removes live front-end dependence on Autodesk auth and reduces operational risk for a public site.

## Publishing templates

A template is a pre-mapped view of a project that decides which parts of the payload actually reach WordPress. Editors pick one instead of hand-selecting fields on every publish.

| Template | Publishes |
|---|---|
| `full` | Description, every metric, all assets and the thumbnail |
| `summary` | Title, summary and thumbnail only |
| `metrics` | Summary plus the full metric table, no downloads |
| `sustainability` | Only environment and carbon metrics, for public reporting |
| `downloads` | Summary plus the asset list, no analysis figures |

Templates only ever remove or constrain content — they never invent fields, so a template can be reasoned about as a filter over the canonical payload. The chosen template is stored with the project and **re-applied on every scheduled sync refresh**, so an unattended refresh can never widen what a page exposes.

## Rendering

Four blocks (in the **Forma** category) and four matching shortcodes:

| Shortcode | Block | Renders |
|---|---|---|
| `[forma_project_list]` | Forma Project List | A grid of published projects |
| `[forma_project id="123"]` | Forma Project | One project, by post ID or Autodesk source ID |
| `[forma_metrics project="123"]` | Forma Metrics | Analysis metrics, as a table or cards |
| `[forma_assets project="123"]` | Forma Assets | Published files and images |

Every template can be overridden from a theme — copy any file out of the plugin's `templates/` directory into `your-theme/forma-publisher/`.

## Security

The full model is documented in [docs/security.md](docs/security.md). In short:

- Every inbound publish request carries an HMAC-SHA256 signature over the method, route, timestamp, nonce and a hash of the raw body.
- Requests outside the timestamp tolerance are rejected as stale; repeated nonces are rejected as replays; each connection is rate limited.
- Signature comparison is constant time, and unknown key IDs spend comparable work so timing cannot confirm whether a key exists.
- Remote media import is off by default and, when enabled, restricted to an explicit host allow list.
- WordPress holds no Autodesk credential of any kind.

## Live verification

The automated suites prove the code against recorded Autodesk responses. To prove it against your own tenant:

```bash
cd backend && npm run verify
```

It reads your configuration, obtains a token, lists your hubs and projects, builds a canonical payload from the first one, checks it against the shared schema, and round-trips a signed request to WordPress. It reports which stage works and which does not, warns on clock drift, and **performs no writes**. Exits non-zero if any check fails.

## Storage

| Setting | Behaviour |
|---|---|
| `DATABASE_URL` unset | JSON file store in `DATA_DIR`. Fine for a single process. |
| `DATABASE_URL` set | PostgreSQL. Mutations take a row lock inside a transaction, so the service is safe to run as more than one instance. |

Both implementations satisfy the same contract test, including a concurrency case that asserts no update is lost. The PostgreSQL store additionally proves that a second instance observes the first one's writes — the property the file store cannot provide.

## Documentation

- [Architecture](docs/architecture.md)
- [Canonical schema](docs/canonical-schema.md)
- [Security model](docs/security.md)
- [Installation and operations](docs/installation.md)

## Development

```bash
# WordPress coding standards
cd wordpress-plugin && composer install && vendor/bin/phpcs

# WordPress integration suite — provisions a throwaway WordPress with SQLite
cd wordpress-plugin && ./tests/setup-wp.sh

# Backend
cd backend && npm test

# Extension
cd forma-extension && npm run build
```

The integration suite exits non-zero on any failed assertion, so it gates CI. To run it against a WordPress install you already have, activate the plugin there and run `wp eval-file tests/run.php`.

CI runs all of the above plus the official WordPress Plugin Check action, a PHP 7.4–8.4 syntax matrix, and the integration suite on PHP 7.4, 8.3 and 8.4.

## Licence

[GPL-2.0-or-later](LICENSE).
