=== Forma Publisher ===
Contributors: ibuilder
Tags: autodesk, forma, publishing, projects, architecture
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receives signed Autodesk Forma content from a trusted backend and publishes it as WordPress projects, metrics and assets.

== Description ==

Forma Publisher is the WordPress half of a source driven publishing workflow for Autodesk Forma. A trusted backend service owns the Autodesk Platform Services credentials, normalizes project data into a stable canonical schema, and pushes signed snapshots to this plugin. WordPress stores and renders that content; it never talks to Autodesk directly and never holds an Autodesk token.

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

== Installation ==

1. Upload the `forma-publisher` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Forma → Connections** and create a connection. Copy the key ID and shared secret; the secret is shown only once.
4. Configure the backend service with the ingest URL shown on **Forma → Settings**, plus the key ID and secret.
5. Optionally define connections in `wp-config.php` instead of the database:

`define( 'FORMA_PUBLISHER_CONNECTIONS', array( 'fp_yourkeyid' => 'your-shared-secret' ) );`

== Frequently Asked Questions ==

= Does this plugin connect to Autodesk directly? =

No. It only accepts signed payloads from your own backend service. The backend holds the Autodesk Platform Services credentials and performs all Autodesk API calls.

= How is a request signed? =

The backend builds a canonical string from the HTTP method, the REST route, a Unix timestamp, a per request nonce, and the SHA-256 hash of the raw request body, joined by newlines. It signs that string with HMAC-SHA256 using the connection secret and sends the result in the `X-Forma-Signature` header, alongside `X-Forma-Key`, `X-Forma-Timestamp` and `X-Forma-Nonce`.

= Will publishing the same project twice create duplicates? =

No. Each project is matched by its Autodesk source ID, so a repeat publish updates the existing post. If the payload is byte for byte identical to the last accepted one, the request is recorded as skipped and nothing is rewritten.

= Can I override the templates? =

Yes. Copy any file from the plugin's `templates` directory into a `forma-publisher` folder in your theme, for example `your-theme/forma-publisher/project-list.php`. A theme level `single-forma_project.php` also takes precedence over the bundled single project template.

= What happens to my content if I uninstall the plugin? =

Options, connections, capabilities, scheduled events and log entries are removed. Published projects and assets are kept so that you do not lose editorial content.

= Does it work on multisite? =

Yes. Each site keeps its own settings, connections and content, and the uninstall routine cleans up every site in the network.

== Changelog ==

= 1.0.0 =
* Initial release.
* Signed ingest endpoint with HMAC-SHA256 verification, replay protection and per connection rate limiting.
* `forma_project` and `forma_asset` content types with metrics, tags, statuses and location metadata.
* Four blocks and four shortcodes with theme template overrides.
* Publish audit log with configurable retention.
* Optional scheduled refresh requests to the backend service.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
