# Canonical schema

Version `1.0`.

The canonical schema is the contract between the backend and WordPress. It is deliberately independent of Autodesk response shapes: the backend normalizes upstream data into this form, and WordPress only ever sees this form. An upstream field rename is absorbed by the transform, not propagated to a public page.

It is defined twice, once per language, and both definitions are tested:

- **Backend:** Zod schemas in [`backend/src/canonical/schema.ts`](../backend/src/canonical/schema.ts)
- **WordPress:** JSON Schema REST arguments in [`wordpress-plugin/publisher-for-autodesk-forma/includes/class-schema.php`](../wordpress-plugin/publisher-for-autodesk-forma/includes/class-schema.php)

## Envelope

```json
{
  "schema_version": "1.0",
  "operation": "publish",
  "mode": "snapshot",
  "job_id": "0f6b1e2c-...",
  "generated_at": "2026-08-13T09:15:00Z",
  "project": { }
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `schema_version` | string | yes | Must be exactly `1.0`. A future version is rejected rather than guessed at. |
| `operation` | enum | yes | `publish`, `update`, `unpublish`, `archive`, `delete` |
| `mode` | enum | no | `snapshot` (default) or `sync` |
| `job_id` | string | yes | Backend job identifier, echoed into the audit log |
| `generated_at` | date-time | no | When the payload was built |
| `project` | object | yes | See below. Unknown properties are rejected. |

### Operation semantics

| Operation | Effect in WordPress |
|---|---|
| `publish` / `update` | Upsert the project by `source_id`; sync assets; prune assets no longer present |
| `unpublish` | Set post status to `draft`, record state `unpublished` |
| `archive` | Set post status to `private`, record state `archived` |
| `delete` | Move the project and its assets to the trash — never a permanent delete |

`publish` and `update` are interchangeable in effect. Both are accepted so the caller can express intent, and so the audit log distinguishes a first publish from a later revision.

## Project

| Field | Type | Required | Constraints |
|---|---|---|---|
| `source_id` | string | **yes** | 1–255. The stable Autodesk identifier; the primary key for matching. |
| `title` | string | **yes** | 1–255 |
| `source_system` | string | no | ≤64, defaults to `autodesk-forma` |
| `slug` | string | no | ≤200 |
| `summary` | string | no | ≤2000, becomes the post excerpt |
| `content` | string | no | ≤200000, filtered through `wp_kses_post` |
| `status` | enum | no | `publish`, `draft`, `pending`, `private`. Falls back to the site's configured default. |
| `source_url` | uri | no | Link back to Forma |
| `hub_id`, `project_id`, `proposal_id` | string | no | ≤255 each, stored as meta |
| `source_updated_at` | date-time | no | Upstream modification time |
| `tags` | string[] | no | ≤50 items, mapped to the `forma_project_tag` taxonomy |
| `statuses` | string[] | no | ≤20 items, mapped to the `forma_project_status` taxonomy |
| `location` | object | no | `latitude` (−90…90), `longitude` (−180…180), `address` (≤500) |
| `featured_image` | object | no | `url` (required), `alt`, `filename`. Only imported when media import is enabled and the host is allow-listed. |
| `metrics` | object[] | no | ≤200 items |
| `assets` | object[] | no | ≤200 items |

## Metric

| Field | Type | Required | Constraints |
|---|---|---|---|
| `key` | string | **yes** | ≤100, normalized to lowercase with underscores |
| `label` | string | no | ≤200. Derived from the key when omitted. |
| `value` | number \| string \| null | no | Numeric values are formatted with `number_format_i18n` |
| `unit` | string | no | ≤32, appended after the value |
| `category` | string | no | ≤100, used by the `category` filter on the metrics view |
| `precision` | integer | no | 0–8, decimal places for numeric values |

The backend recognises common Forma analysis keys and supplies a readable label, unit and category automatically — `gfa`, `far`, `footprint`, `site_area`, `building_count`, `max_height`, `dwelling_units`, `sun_hours`, `daylight_potential`, `wind_comfort`, `operational_carbon`, `embodied_carbon`, `noise_level`. Unknown keys are title-cased rather than dropped, so a new analysis output still publishes with a sensible label.

Both an object map (`{ "gfa": 48250.5 }`) and an array of metric objects are accepted upstream; both normalize to the same array form.

## Asset

| Field | Type | Required | Constraints |
|---|---|---|---|
| `source_id` | string | **yes** | ≤255. Matching key; also how stale assets are detected. |
| `title` | string | **yes** | ≤255 |
| `kind` | enum | no | `image`, `document`, `model`, `dataset`, `link`. Inferred from MIME type and extension. |
| `url` | uri | no | Rendered as an external link with `rel="nofollow noopener external"` |
| `mime_type` | string | no | ≤128 |
| `size` | integer | no | Bytes, rendered with `size_format` |
| `checksum` | string | no | ≤128 |
| `summary` | string | no | ≤2000 |

## Example

```json
{
  "schema_version": "1.0",
  "operation": "publish",
  "mode": "snapshot",
  "job_id": "job-8f21",
  "generated_at": "2026-08-13T09:15:00Z",
  "project": {
    "source_id": "urn:adsk.forma:proposal:abc123",
    "source_system": "autodesk-forma",
    "title": "Harbour District Massing Study",
    "summary": "A mixed use massing study for the harbour district.",
    "content": "<p>Concept massing with three towers.</p>",
    "status": "publish",
    "source_url": "https://app.autodesk.com/forma/proposal/abc123",
    "hub_id": "b.hub123",
    "project_id": "b.proj456",
    "tags": [ "Mixed Use", "Waterfront" ],
    "statuses": [ "Concept" ],
    "location": { "latitude": 59.91, "longitude": 10.75, "address": "Oslo, Norway" },
    "metrics": [
      { "key": "gfa", "label": "Gross floor area", "value": 48250.5, "unit": "m²", "category": "Area", "precision": 1 },
      { "key": "sun_hours", "label": "Average sun hours", "value": 4.8, "unit": "h", "category": "Environment", "precision": 1 }
    ],
    "assets": [
      { "source_id": "urn:asset:1", "title": "Site plan", "kind": "document", "url": "https://example.com/site-plan.pdf", "mime_type": "application/pdf", "size": 204800 }
    ]
  }
}
```

## Versioning

`schema_version` is checked as an exact match. A payload declaring an unknown version is rejected with HTTP 400 rather than partially applied.

A breaking change means a new version string and a plugin release that accepts both during a transition window. Additive, optional fields do not require a version bump — but note that `additionalProperties: false` means a plugin older than the backend will reject new fields, so roll the plugin out first.
