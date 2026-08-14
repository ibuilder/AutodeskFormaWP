# Rendering reference

Four blocks, four matching shortcodes, and full template overrides. Every view escapes on output, and none of them can render content the current user is not allowed to see.

## Shortcodes

### `[forma_project_list]`

A grid of published projects.

| Attribute | Default | Values |
|---|---|---|
| `limit` | `10` | 1–50 |
| `columns` | `3` | 1–6 |
| `orderby` | `date` | `date`, `title`, `modified`, `menu_order`, `rand` |
| `order` | `DESC` | `ASC`, `DESC` |
| `tag` | — | Comma-separated Forma tag slugs |
| `status` | — | Comma-separated Forma status slugs |
| `show_excerpt` | `yes` | `yes`, `no` |
| `show_thumbnail` | `yes` | `yes`, `no` |
| `show_metrics` | `no` | `yes`, `no` — shows the first three metrics |

```
[forma_project_list limit="6" columns="3" tag="waterfront" show_metrics="yes"]
```

### `[forma_project]`

One project.

| Attribute | Default | Values |
|---|---|---|
| `id` | — | **Required.** Post ID or Autodesk source ID |
| `show_metrics` | `yes` | `yes`, `no` |
| `show_assets` | `yes` | `yes`, `no` |
| `show_thumbnail` | `yes` | `yes`, `no` |
| `show_content` | `yes` | `yes`, `no` |

```
[forma_project id="urn:adsk.forma:proposal:abc123" show_assets="no"]
```

### `[forma_metrics]`

The analysis metrics for a project.

| Attribute | Default | Values |
|---|---|---|
| `project` | — | **Required.** Post ID or source ID |
| `layout` | `table` | `table`, `cards` |
| `category` | — | Comma-separated metric categories |
| `keys` | — | Comma-separated metric keys |

```
[forma_metrics project="123" layout="cards" category="environment,carbon"]
```

### `[forma_assets]`

Files and images published for a project.

| Attribute | Default | Values |
|---|---|---|
| `project` | — | **Required.** Post ID or source ID |
| `kind` | — | `image`, `document`, `model`, `dataset`, `link` |
| `limit` | `50` | 1–200 |

```
[forma_assets project="123" kind="document"]
```

## Blocks

Each shortcode has an equivalent block in the **Forma** category, with the same options in the sidebar. All four are server rendered, so a published page always reflects current content rather than markup frozen at edit time.

| Block | Equivalent |
|---|---|
| Forma Project List | `[forma_project_list]` |
| Forma Project | `[forma_project]` |
| Forma Metrics | `[forma_metrics]` |
| Forma Assets | `[forma_assets]` |

The blocks ship without a build step, so no compiled bundle is distributed and nothing needs rebuilding to install the plugin.

## Visibility rules

These apply to every view:

- A project that is not published renders only for a user who can read it. Visitors get nothing at all — no placeholder, no title.
- Diagnostic notices such as *"That Forma project could not be found"* appear **only** to users who can edit projects. A visitor sees an empty string, so a mistyped ID never leaks integration detail onto a public page.

## Template overrides

Copy any file from the plugin's `templates/` directory into a `forma-publisher` folder in your theme:

```
your-theme/forma-publisher/project-list.php
your-theme/forma-publisher/project.php
your-theme/forma-publisher/metrics.php
your-theme/forma-publisher/assets.php
your-theme/forma-publisher/single-project.php
```

Resolution order is child theme, then parent theme, then the plugin. A theme-level `single-forma_project.php` in the theme root also takes precedence over the bundled single-project template.

### Template variables

Templates receive one array, `$forma_publisher_data`. The keys vary by template:

| Template | Keys |
|---|---|
| `project-list.php` | `projects`, `columns`, `show_excerpt`, `show_thumbnail`, `show_metrics` |
| `project.php` | `project`, `metrics`, `assets`, `location`, `show_thumbnail`, `show_content` |
| `metrics.php` | `project`, `metrics`, `layout` |
| `assets.php` | `project`, `assets` |

**You are responsible for escaping in an override.** The bundled templates escape everything: `esc_html()` for text, `esc_url()` for links, `esc_attr()` for attributes. Copy that discipline — the plugin cannot escape on your behalf once you take over rendering.

Useful helpers, both of which return already-formatted plain text:

```php
Forma_Publisher\Renderer::format_metric( $metric );      // "48,250.5 m²" or "—"
Forma_Publisher\Renderer::asset_kind_label( $kind );     // "Document"
```

## Styling

One stylesheet, `forma-publisher`, is enqueued only on pages that actually render a view. Class names are prefixed `forma-publisher-`:

| Class | Element |
|---|---|
| `.forma-publisher-list` | Project grid wrapper |
| `.forma-publisher-card` | One project in the grid |
| `.forma-publisher-project` | Single project wrapper |
| `.forma-publisher-metrics--table` / `--cards` | Metric layouts |
| `.forma-publisher-asset` | One asset row |
| `.forma-publisher-empty` | "Nothing published yet" message |

The stylesheet uses CSS custom properties, so a theme can restyle without overriding rules:

```css
.forma-publisher {
	--forma-border: #d0d5dd;
	--forma-muted: #667085;
	--forma-radius: 10px;
	--forma-gap: 2rem;
}
```

To drop the bundled CSS entirely:

```php
add_action( 'wp_enqueue_scripts', function () {
	wp_dequeue_style( 'forma-publisher' );
}, 20 );
```

## Post types and taxonomies

| Object | Name | Public |
|---|---|---|
| Project | `forma_project` | yes, archive at `/forma-projects/` |
| Asset | `forma_asset` | no, not publicly queryable |
| Log entry | `forma_log` | no, hidden from admin and search |
| Tag | `forma_project_tag` | yes |
| Status | `forma_project_status` | yes, hierarchical |

Assets are deliberately not publicly queryable: they are rendered as part of a project, not as standalone pages, so they never compete in search results with the project itself.
