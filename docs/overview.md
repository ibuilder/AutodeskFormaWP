# Overview

Publisher for Autodesk Forma moves content from **Autodesk Forma** into **WordPress** as curated, snapshot-friendly project pages.

It is deliberately not a plugin that queries Autodesk when a visitor arrives. Forma is the source environment where someone selects content and triggers a publish; a backend service you control holds every Autodesk credential; WordPress receives a finished, signed snapshot and renders it locally.

## Start here

| If you want to… | Read |
|---|---|
| Get it running | [Installation & operations](installation.md) |
| Know what every setting does | [Configuration reference](configuration.md) |
| Understand the design | [Architecture](architecture.md) |
| Integrate your own backend | [Canonical schema](canonical-schema.md) and [WordPress REST API](rest-api.md) |
| Protect editorial work | [Editorial review](editorial-review.md) |
| Review the threat model | [Security model](security.md) |
| Display content in a theme | [Blocks, shortcodes & templates](rendering.md) |
| Extend the plugin | [Hooks & post meta](hooks.md) |

## The three components

| Component | Holds credentials | Responsibility |
|---|---|---|
| **Forma extension** | Backend API key only | Select content, preview the payload, trigger publishes, view history |
| **Backend service** | **All Autodesk tokens** | OAuth, Autodesk reads, normalization, signing, retries |
| **WordPress plugin** | Its own shared secret | Verify signatures, store content, render pages, audit |

Traffic flows one way: extension → backend → WordPress. WordPress never calls Autodesk, and the extension never calls WordPress.

## Three properties that follow

**Autodesk tokens never reach a browser or WordPress.** They exist only in the backend, encrypted at rest with AES-256-GCM. This holds by construction rather than by policy: the plugin has no Autodesk client and no code path that reaches Autodesk.

**The public site has no runtime dependency on Autodesk.** Pages render from local content, so an upstream outage, a rate limit or an expired credential cannot break a published page.

**WordPress is never bound to Autodesk response shapes.** Everything is normalized into a versioned [canonical schema](canonical-schema.md) first, so an upstream field rename is absorbed by the transform rather than becoming a broken page.

## Publishing modes

| Mode | Behaviour |
|---|---|
| **Snapshot** (default) | Content is copied at publish time. Editorially stable, no live dependency. |
| **Sync** | The link is retained. WordPress asks the backend to re-push on a schedule, and the backend rebuilds each project from Autodesk unattended. |

Snapshot is the default deliberately: it removes live front-end dependence on Autodesk auth and reduces operational risk for a public site.

## What it will not do

Being clear about the boundaries is more useful than a feature list:

- **It does not write to Autodesk.** Access is read-only by design; the requested scopes are `data:read account:read`.
- **It does not expose every Forma entity.** The canonical schema covers projects, metrics and assets. Anything outside that is not published.
- **It does not render live Autodesk data.** By the time a visitor sees a page, the content is local.
- **It does not authenticate visitors against Autodesk.** Published content is public WordPress content, subject to normal WordPress visibility rules.

## Verification

Every figure here comes from a CI run rather than a claim:

| Check | Result |
|---|---|
| WordPress Plugin Check — all categories, severity 1, experimental | No errors, no warnings |
| WordPress Coding Standards (WPCS 3) | Clean |
| PHP compatibility | 7.4 – 8.4 |
| Plugin suite, single site | 337 assertions |
| Plugin suite, multisite network | 360 assertions |
| Backend tests | 62, or 70 including the PostgreSQL contract |
| Node signer ↔ PHP verifier interop | Byte-identical signatures |

The plugin suite runs against a real WordPress install rather than mocks, so REST dispatch, capabilities, cron, the object cache and the uninstall routine behave as they do in production.

## A note on Autodesk metrics

The metric pipeline — normalization, labels, units, filtering and rendering — is covered by tests against recorded Autodesk response shapes. Which upstream endpoint supplies Forma *analysis* metrics varies by entitlement, so the backend also accepts metrics supplied by the extension from embedded-view context.

Run `npm run verify` in `backend/` against your own tenant to confirm this end to end. It reports which stage works, warns when no metrics are found, and publishes nothing.
