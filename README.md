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
| Plugin functional suite (WP 7.0.4, live REST dispatch) | 72 assertions passing |
| Backend unit tests | 21 passing |
| Cross-language signature interop (Node signer ↔ PHP verifier) | Byte-identical |

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
| **Sync** | A source-to-target link is retained. WordPress asks the backend to re-push on a schedule. |

Snapshot is the default deliberately: it removes live front-end dependence on Autodesk auth and reduces operational risk for a public site.

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

## Documentation

- [Architecture](docs/architecture.md)
- [Canonical schema](docs/canonical-schema.md)
- [Security model](docs/security.md)
- [Installation and operations](docs/installation.md)

## Development

```bash
# WordPress coding standards
cd wordpress-plugin && composer install && vendor/bin/phpcs

# Backend
cd backend && npm test

# Extension
cd forma-extension && npm run build
```

CI runs all of the above plus the official WordPress Plugin Check action and a PHP 7.4–8.4 syntax matrix.

## Licence

[GPL-2.0-or-later](LICENSE).
