# WordPress.org submission readiness

An audit of this plugin against the [detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/), the [Plugin Check](https://wordpress.org/plugins/plugin-check/) tool, and current advice from the [plugins team](https://make.wordpress.org/plugins/).

**Status: no known blockers.** Both original blockers are resolved.

## Resolved blockers

### 1. The slug began with a trademark (guideline 17) — resolved

The slug was `forma-publisher` and the name *Forma Publisher*. Both began with **Forma**, a trademark of Autodesk, Inc.

> Plugin slugs cannot begin with trademarked terms unless legal ownership or representation is confirmed. Non-representatives should use formats like "Feature for Brand" rather than "Brand Feature".

This is one of the most common reasons a submission is rejected, and it is judged by a human — Plugin Check passed throughout and would have kept passing.

Renamed to **`publisher-for-autodesk-forma`** / *Publisher for Autodesk Forma* using `bin/rename-slug.sh`. Both terms remain in the name for discoverability, neither leads.

The slug is also the text domain, so the rename updated every translation call and every `block.json` textdomain field, and the `.pot` was regenerated against the new domain.

**Deliberately unchanged:** block names (`forma-publisher/project` and friends), CSS class names, the REST namespace (`forma-publisher/v1`), option names, meta keys and the `Forma_Publisher` PHP namespace. Block names are written into saved post content, so renaming them would silently break existing pages. None of these are required to match the slug — only the text domain is.

**Changed as a consequence:** the theme template override folder is now `your-theme/publisher-for-autodesk-forma/`. Anyone with overrides under the old name must move them. Recorded in the readme upgrade notice.

### 2. The Contributors field — resolved

`readme.txt` previously declared `Contributors: ibuilder`, and
`https://profiles.wordpress.org/ibuilder/` returns 404. That account does not
exist, which fails review.

Now set to **`blackrebel`**, verified against
[profiles.wordpress.org/blackrebel](https://profiles.wordpress.org/blackrebel/)
— display name *iBuilder*, member since 2012, three published plugins.

Contributors must be existing WordPress.org usernames, since they become the
listed authors and control the plugin. Note that a WordPress.org account is
separate from a GitHub account, which is why the two differ here.

## Passing

| Guideline | Status | Evidence |
|---|---|---|
| 1. GPL compatible | Pass | `GPL-2.0-or-later` in the header and readme; `LICENSE` ships inside the plugin. No third-party code is bundled. |
| 2. Developer responsibility | Pass | All code is first-party. Autodesk API terms apply to the backend you run, not to this plugin. |
| 3. Stable version | Pass | `Stable tag` matches the header version; a packaging test asserts they agree. |
| 4. Human readable code | Pass | No minification, no obfuscation, no build step. The blocks ship as readable JavaScript. |
| 5. No trialware | Pass | Every feature works. Nothing is time limited, keyed or upsold. |
| 6. Serviceware | Pass | The companion backend is GPL, free, in the same repository and self-hosted. There is no author-operated service and nothing to buy. |
| 7. No unauthorised tracking | Pass | **No telemetry of any kind.** With default settings the plugin makes no outbound request at all. Documented in the readme's *External services* section. |
| 8. No third-party executable code | Pass | Nothing is loaded or executed from a remote source. No CDN, no remote scripts, no admin iframes. |
| 9. Honest conduct | Pass | No SEO manipulation, no review incentives, no misrepresentation. |
| 10. No unauthorised links | Pass | No "powered by" output, no footer credits, no referral links. |
| 11. No dashboard hijacking | Pass | Zero `admin_notices` hooks. All plugin messaging lives on the plugin's own screens. |
| 12. No readme spam | Pass | Five tags, all directly relevant. No affiliate links, no keyword stuffing, no competitor tags. |
| 13. Use bundled libraries | Pass | No libraries are bundled. Blocks use the `wp-*` script handles WordPress already provides. |
| 14. Avoid frequent commits | N/A | Applies to SVN after acceptance. |
| 15. Increment versions | Pass | 1.0.0 → 1.1.0, with matching readme changelog and upgrade notice entries. |
| 16. Complete at submission | Pass | Fully functional: it receives, validates, stores, renders and audits content today. |
| 17. Trademarks | **Blocked** | See above. |
| 18. Directory rights | N/A | — |

### Plugin Check

Clean at version 1.1.0 across **all categories at severity 1 with experimental checks enabled** — the strictest setting available:

```
wp plugin check <slug> --categories=general,plugin_repo,security,performance,accessibility \
  --severity=1 --include-experimental
→ Success: Checks complete. No errors found.
```

CI runs the official `wordpress/plugin-check-action` against the built archive on every push, so this cannot regress unnoticed.

## Static analysis findings that are intentional

The `wporg-submit` preflight checker reports nine remaining errors. Both groups are deliberate, and the official Plugin Check passes all of them.

### Four `echo $markup` calls in block render files

`blocks/*/render.php` each end with:

```php
echo $forma_publisher_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
```

The value is markup assembled by the plugin's own templates, every one of which escapes at the point of output — `esc_html()`, `esc_url()`, `esc_attr()`. A static checker cannot see across that boundary.

Three similar calls were removed in 1.2.0 by adding `Templates::output()`, which writes a template straight to the output buffer rather than capturing it into a string only to echo it. The four that remain genuinely need the string: each block inspects whether the markup is empty before deciding to emit a wrapper element at all.

Running the assembled markup through `wp_kses_post()` was considered and rejected. It would silently strip legitimate markup from **theme template overrides**, which are a documented feature, and would break the contract that a theme's own template renders verbatim.

### Five `FORMA_PUBLISHER_*` constants

The checker wants a prefix derived from the slug. The actual requirement is that global symbols carry a **unique** prefix of at least four characters, which `FORMA_PUBLISHER_` satisfies unambiguously.

They are kept because `FORMA_PUBLISHER_CONNECTIONS` is a documented `wp-config.php` constant that site owners may already have set; renaming it would break those installations silently. The PHP namespace, option names and meta keys use the same prefix, so the codebase stays internally consistent.

### One `uninstall.php` ABSPATH warning

`uninstall.php` guards with `defined( 'WP_UNINSTALL_PLUGIN' ) || exit;`, which is the guard WordPress documents for that file specifically. An `ABSPATH` check would be the wrong one. This is a false positive.

## Points a reviewer may raise, and the answers

**"What does this plugin do without the backend?"**
It is a complete receiver: signed REST endpoints, content types, four blocks, four shortcodes, template overrides, an audit log and an editorial review queue all function on their own. The backend supplies content, in the same way an editor otherwise would. The backend is GPL, free and in the same repository.

**"Does it phone home?"**
No. There is no author-controlled endpoint anywhere in the code — this is verifiable by searching the plugin for any hardcoded external host. The only two outbound paths are a scheduled refresh to a URL the administrator types in, and image downloads from an explicit host allow list. Both are off by default.

**"Why does it send data to an external service?"**
The refresh request contains the site URL, a timestamp and the schema version. Nothing else, and no personal data. It goes only to the administrator's own server.

**"Is this affiliated with Autodesk?"**
No, and the readme says so explicitly in the FAQ.

## Pre-submission checklist

- [x] Rename the slug to `publisher-for-autodesk-forma`
- [x] Set a real WordPress.org username in `Contributors` — `blackrebel`, verified
- [x] Regenerate the `.pot` against the new text domain — 290 strings
- [x] `vendor/bin/phpcs` clean
- [x] Plugin Check clean, all categories, severity 1, experimental
- [x] Integration suite: 337 single site, 360 multisite
- [x] Disclose external services in the readme
- [x] Ship the GPL text inside the plugin
- [ ] Confirm `Tested up to` still matches the current WordPress release on the day you submit
- [ ] Build the archive with `./bin/build-zip.sh` and upload that file

## What has and has not been verified

Verified by running it:

- Plugin Check, PHPCS, the full integration suite on single site and multisite, and the packaging suite.
- That the built archive installs into WordPress and passes the suite as the installed copy.

**Not** verified, and not verifiable without a reviewer:

- Whether a human reviewer agrees with the judgement calls above.
- Whether the plugin's behaviour against a real Autodesk tenant matches the documentation. Run `npm run verify` in `backend/` to check that half.

Static analysis and Plugin Check together are necessary but not sufficient. The directory requires a human review, and no tool can predict its outcome.

## After submission

Reviews are taking roughly a week, against record submission volume. The plugins team notes that **38.7% of plugins receive no reply from their authors**, and that responsiveness correlates strongly with approval — 69.5% of reviewed plugins were approved in 2025. If the reviewer writes, reply promptly; that single behaviour is the largest controllable factor in the outcome.
