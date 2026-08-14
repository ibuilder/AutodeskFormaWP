# Hooks reference

Actions and filters for extending the plugin without modifying it.

## Actions

### `forma_publisher_ingested`

Fires after a publish operation has been applied, before the response is returned.

```php
do_action( 'forma_publisher_ingested', array $result, array $payload, string $connection_id );
```

| Parameter | Contents |
|---|---|
| `$result` | Operation result: `status`, `post_id`, `source_id`, `permalink`, `assets`, `payload_hash` |
| `$payload` | The full canonical payload as accepted |
| `$connection_id` | The verified connection key ID |

Check `$result['status']` before acting — it may be `unchanged`, `held_for_review` or `skipped_local_edit`, none of which changed content.

```php
add_action( 'forma_publisher_ingested', function ( $result, $payload, $connection_id ) {
	if ( ! in_array( $result['status'], array( 'created', 'updated' ), true ) ) {
		return;
	}

	// Purge a CDN, ping a search index, notify a channel.
	my_cdn_purge( $result['permalink'] );
}, 10, 3 );
```

### `forma_publisher_content_changed`

Fires whenever published content changes. Carries no arguments — it is a cache-invalidation signal.

```php
add_action( 'forma_publisher_content_changed', function () {
	wp_cache_flush_group( 'my_project_cache' );
} );
```

## Filters

### `forma_publisher_rate_limit`

Verified requests accepted per connection per minute. Default `60`.

```php
add_filter( 'forma_publisher_rate_limit', function ( $limit, $key_id ) {
	return 'fp_bulkimporter' === $key_id ? 600 : $limit;
}, 10, 2 );
```

### `forma_publisher_unverified_rate_limit`

Failed authentications accepted per origin address per minute. Default `20`. Return `0` to disable the limiter — sensible only when a proxy in front already limits by IP.

```php
add_filter( 'forma_publisher_unverified_rate_limit', function () {
	return 5;
} );
```

Raising this weakens protection against credential probing. Lowering it can lock out a legitimate backend that is misconfigured, which is usually what you want: it fails loudly.

### `forma_publisher_template_candidates`

Filters the paths searched for a template, in priority order. Use it to add a location, such as a plugin providing its own overrides.

```php
add_filter( 'forma_publisher_template_candidates', function ( $candidates, $name ) {
	array_unshift( $candidates, plugin_dir_path( __FILE__ ) . 'forma-templates/' . $name );

	return $candidates;
}, 10, 2 );
```

Paths are validated before use: only a name matching `^[a-z0-9-]+\.php$` is ever resolved, so a filter cannot be used to load an arbitrary file.

## Post meta

Read these to build custom views. All are prefixed and protected, so they do not appear in the custom fields UI.

### Project meta

| Key | Contents |
|---|---|
| `_forma_source_id` | Autodesk identifier. The matching key for all updates. |
| `_forma_source_system` | Normally `autodesk-forma` |
| `_forma_source_url` | Link back to Forma |
| `_forma_hub_id`, `_forma_project_id`, `_forma_proposal_id` | Autodesk identifiers |
| `_forma_metrics` | Array of metric rows |
| `_forma_location` | `latitude`, `longitude`, `address` |
| `_forma_sync_mode` | `snapshot` or `sync` |
| `_forma_payload_hash` | Hash of the last accepted payload |
| `_forma_last_synced` | ISO 8601 timestamp |
| `_forma_publish_state` | `published`, `unpublished` or `archived` |
| `_forma_connection_id` | Connection that last wrote this project |
| `_forma_synced_modified` | Post modification time at the last sync, used for local edit detection |
| `_forma_held_payload` | An update parked for review, when present |

### Asset meta

| Key | Contents |
|---|---|
| `_forma_source_id` | Autodesk identifier |
| `_forma_parent_project` | Parent project post ID |
| `_forma_asset_kind` | `image`, `document`, `model`, `dataset`, `link` |
| `_forma_asset_url` | External URL |
| `_forma_asset_mime` | MIME type |
| `_forma_asset_size` | Bytes |
| `_forma_asset_checksum` | Upstream checksum |

### Querying by source ID

Use the repository rather than a raw meta query — it caches lookups:

```php
$repository = new Forma_Publisher\Repository();

$post = $repository->resolve_project( 'urn:adsk.forma:proposal:abc' );
$assets = $repository->assets_for_project( $post->ID );
$metrics = Forma_Publisher\Renderer::metrics_for( $post->ID );
```

## Capabilities

| Capability | Default roles | Controls |
|---|---|---|
| `forma_manage_settings` | Administrator | Settings and connections |
| `forma_view_logs` | Administrator, Editor | The publish audit trail |
| `edit_forma_projects` | Administrator, Editor | Project content and the review queue |
| `publish_forma_projects` | Administrator, Editor | Approving pending projects |
| `edit_forma_assets` | Administrator, Editor | Asset content |

Grant to a custom role:

```php
$role = get_role( 'project_manager' );
$role->add_cap( 'edit_forma_projects' );
$role->add_cap( 'forma_view_logs' );
```

Capabilities are granted on activation and removed on uninstall. A role created after activation does not receive them automatically.
