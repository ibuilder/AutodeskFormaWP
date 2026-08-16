=== Publisher for Autodesk Forma ===
Contributors: blackrebel
Tags: autodesk, forma, publishing, projects, architecture
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receives signed Autodesk Forma content from a trusted backend and publishes it as WordPress projects, metrics and assets.

== Description ==

Publisher for Autodesk Forma is the WordPress half of a source driven publishing workflow for Autodesk Forma. A trusted backend service owns the Autodesk Platform Services credentials, normalizes project data into a stable canonical schema, and pushes signed snapshots to this plugin. WordPress stores and renders that content; it never talks to Autodesk directly and never holds an Autodesk token.

This split keeps the public website fast and independent: pages render from local content instead of live third party API calls, and an outage or credential change upstream cannot break the front end.

**What the plugin does**

* Registers a signed ingest endpoint at `/wp-json/forma-publisher/v1/ingest`.
* Verifies HMAC-SHA256 signatures, rejects replayed and stale requests, and rate limits each connection.
* Creates and updates `forma_project` and `forma_asset` content, including metrics, tags, statuses and location metadata.
* Stores source identifiers, payload hashes and sync timestamps so repeat publishes update the same item instead of duplicating it.
* Records every publish, update, unpublish and archive request in an audit log.
* Renders content through four blocks and four matching shortcodes, with full theme template overrides.

**Blocks and shortcodes**

* `[forma_project_list]` — a grid of published projects.
* `[forma_project id="123"]` — a single project, by post ID or Autodesk source ID.
* `[forma_metrics project="123"]` — the analysis metrics for a project.
* `[forma_assets project="123"]` — the files and images published for a project.

Every shortcode has an equivalent block in the **Forma** block category.

**Security model**

* Autodesk credentials stay in the backend service. This plugin stores none.
* Every inbound request must carry a valid signature from a registered connection.
* Shared secrets can be defined in `wp-config.php` through the `FORMA_PUBLISHER_CONNECTIONS` constant so they never touch the database.
* Remote media import is disabled by default and, when enabled, only downloads from an explicit host allow list.
* Publishing, log access and settings are gated behind dedicated capabilities.

**Do I need anything besides this plugin?**

Yes. This plugin is the receiving half of a two-part system. The companion backend service holds the Autodesk credentials and pushes content here. It is included in the same project, is GPL licensed, is free, and you run it on your own infrastructure — there is no paid tier, no hosted service and no account to buy. The plugin itself is fully functional on its own terms: it receives, stores, renders and audits content, and every one of those parts works without the backend present.

== Installation ==

1. Upload the `publisher-for-autodesk-forma` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Forma → Connections** and create a connection. Copy the key ID and shared secret; the secret is shown only once.
4. Configure the backend service with the ingest URL shown on **Forma → Settings**, plus the key ID and secret.
5. Optionally define connections in `wp-config.php` instead of the database:

`define( 'FORMA_PUBLISHER_CONNECTIONS', array( 'fp_yourkeyid' => 'your-shared-secret' ) );`

== External services ==

This plugin contacts **no service operated by the plugin author**. It has no telemetry, no licence check and no update server. Out of the box, with default settings, it makes no outbound requests at all.

It can make outbound requests in exactly two cases, both of which are off by default and both of which go only to a host you enter yourself:

**1. Scheduled refresh to your own backend service**

Only when you set a backend URL, choose a refresh interval other than Disabled, and select a signing connection under **Forma → Settings**. WordPress then sends a signed POST to `{your backend URL}/api/refresh` on that schedule.

* What is sent: your site URL, the request timestamp, and the canonical schema version. Nothing else, and no personal data.
* Where it goes: the URL you entered. The backend is the companion service in this project's repository, which you host. It is GPL licensed and free.
* Why: it asks your backend to re-publish projects you marked for synchronization.

**2. Downloading a featured image**

Only when you switch on **Remote media import** and add host names to the allowed list. WordPress then downloads images referenced in an incoming payload, and only from hosts on that list. There is no wildcard, and with the list empty every image is skipped.

* What is sent: an ordinary HTTP GET for the image. No site data is transmitted.
* Where it goes: the hosts you listed.

Both features are disabled by default. If you never enable them, this plugin makes no external requests.

**There is no third-party service, so there are no third-party terms to accept.** Every host this plugin can contact is one you enter yourself, and the backend is software you run on your own infrastructure. Its terms of use and privacy policy are therefore your own. No data is sent to the plugin author, and no account, licence key or subscription is involved.

The plugin never contacts Autodesk. Autodesk credentials are held only by the backend service you run. If you use that backend, its use of the Autodesk Platform Services APIs is governed by the Autodesk Platform Services terms (https://aps.autodesk.com/en/terms) and the Autodesk privacy statement (https://www.autodesk.com/company/legal-notices-trademarks/privacy-statement) — but those apply to the backend you operate, not to this plugin.

The backend service is open source and GPL licensed, distributed with this plugin's project: https://github.com/ibuilder/AutodeskFormaWP

== Frequently Asked Questions ==

= Does this plugin connect to Autodesk directly? =

No. It only accepts signed payloads from your own backend service. The backend holds the Autodesk Platform Services credentials and performs all Autodesk API calls.

= How is a request signed? =

The backend builds a canonical string from the HTTP method, the REST route, a Unix timestamp, a per request nonce, and the SHA-256 hash of the raw request body, joined by newlines. It signs that string with HMAC-SHA256 using the connection secret and sends the result in the `X-Forma-Signature` header, alongside `X-Forma-Key`, `X-Forma-Timestamp` and `X-Forma-Nonce`.

= Will publishing the same project twice create duplicates? =

No. Each project is matched by its Autodesk source ID, so a repeat publish updates the existing post. If the payload is byte for byte identical to the last accepted one, the request is recorded as skipped and nothing is rewritten.

= Can I override the templates? =

Yes. Copy any file from the plugin's `templates` directory into a `publisher-for-autodesk-forma` folder in your theme, for example `your-theme/publisher-for-autodesk-forma/project-list.php`. A theme level `single-forma_project.php` also takes precedence over the bundled single project template.

= What happens to my content if I uninstall the plugin? =

Options, connections, capabilities, scheduled events and log entries are removed. Published projects and assets are kept so that you do not lose editorial content.

= Does it work on multisite? =

Yes. Each site keeps its own settings, connections and content, and the uninstall routine cleans up every site in the network.

= Is this an official Autodesk plugin? =

No. This is an independent, community developed plugin. It is not affiliated with, endorsed by, or sponsored by Autodesk, Inc. Autodesk and Autodesk Forma are trademarks of Autodesk, Inc., used here only to describe what the plugin interoperates with.

== Changelog ==

= 1.2.1 =
* The Plugin URI now points at the plugin's documentation page rather than a bare repository root.
* The licence is declared identically in the plugin header and the readme.
* Corrected the plugin name in the description.

= 1.2.0 =
* Renamed the plugin to Publisher for Autodesk Forma, with the slug `publisher-for-autodesk-forma`, so that it does not begin with a trademarked term.
* The text domain changed with the slug. Translations against the old domain need regenerating.
* Block names, shortcodes, CSS classes, settings and stored content are unchanged, so existing pages and published projects are unaffected.
* Theme template overrides now live in `your-theme/publisher-for-autodesk-forma/` rather than `your-theme/forma-publisher/`. Move any override folder you already have.

= 1.1.0 =
* Local edit protection. If a project is edited in WordPress after it was published, an incoming update no longer silently overwrites that work. Choose to hold it for review, keep the local edits, or overwrite.
* Editorial review screen listing held updates and projects awaiting approval, with a count badge in the menu.
* Optional approval step so newly published projects arrive as pending review rather than going live immediately.
* Operator overview screen showing connection status, scheduled refresh, recent failures and recent activity.

= 1.0.0 =
* Initial release.
* Signed ingest endpoint with HMAC-SHA256 verification, replay protection and per connection rate limiting.
* `forma_project` and `forma_asset` content types with metrics, tags, statuses and location metadata.
* Four blocks and four shortcodes with theme template overrides.
* Publish audit log with configurable retention.
* Optional scheduled refresh requests to the backend service.

== Upgrade Notice ==

= 1.2.1 =
Metadata corrections only. No functional change.

= 1.2.0 =
The plugin folder is now `publisher-for-autodesk-forma`. If you installed an earlier build manually, delete the old copy first or WordPress will list both. Projects, settings and connections are unaffected. Theme template overrides must move to the new folder name.

= 1.1.0 =
Adds protection for locally edited projects, an editorial review queue and an operator overview screen. Existing projects are treated as unedited until their next synchronization, so upgrading does not hold everything for review.

= 1.0.0 =
Initial release.
