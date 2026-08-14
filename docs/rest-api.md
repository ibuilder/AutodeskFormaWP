# WordPress REST API reference

Every route lives under the `forma-publisher/v1` namespace and **requires a valid signature**. There is no unauthenticated route, and no route accepts a cookie or an application password: the only credential is a registered connection's shared secret.

Base URL: `https://your-site/wp-json/forma-publisher/v1`

## Authentication

All routes use the same scheme. See [Security model](security.md) for the reasoning.

| Header | Required | Contents |
|---|---|---|
| `X-Forma-Key` | yes | Connection key ID, for example `fp_a1b2c3…` |
| `X-Forma-Timestamp` | yes | Unix timestamp in seconds |
| `X-Forma-Nonce` | yes | Unique per request, 8–128 characters of `A–Z a–z 0–9 _ -` |
| `X-Forma-Signature` | yes | `sha256=` followed by the hex HMAC |
| `Content-Type` | on POST | `application/json` |

The signed string is:

```
METHOD \n ROUTE \n TIMESTAMP \n NONCE \n sha256hex( RAW_BODY )
```

`ROUTE` excludes the `/wp-json` prefix — it is what `WP_REST_Request::get_route()` reports, for example `/forma-publisher/v1/ingest`. For a GET request the body is the empty string, and `sha256hex("")` is the SHA-256 of zero bytes.

### Worked example

```bash
BODY='{"schema_version":"1.0","operation":"publish","job_id":"j1","project":{"source_id":"urn:x","title":"Demo"}}'
ROUTE='/forma-publisher/v1/ingest'
TS=$(date +%s)
NONCE=$(openssl rand -hex 12)
BODY_HASH=$(printf '%s' "$BODY" | openssl dgst -sha256 -hex | awk '{print $2}')
CANON=$(printf 'POST\n%s\n%s\n%s\n%s' "$ROUTE" "$TS" "$NONCE" "$BODY_HASH")
SIG=$(printf '%s' "$CANON" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $2}')

curl -sS -X POST "https://your-site/wp-json$ROUTE" \
  -H "Content-Type: application/json" \
  -H "X-Forma-Key: $KEY_ID" \
  -H "X-Forma-Timestamp: $TS" \
  -H "X-Forma-Nonce: $NONCE" \
  -H "X-Forma-Signature: sha256=$SIG" \
  --data "$BODY"
```

---

## POST /ingest

Applies a canonical payload. This is the only route that writes content.

**Request body:** a canonical payload as defined in [Canonical schema](canonical-schema.md).

**Response 200**

```json
{
  "success": true,
  "result": {
    "status": "created",
    "post_id": 84,
    "source_id": "urn:adsk.forma:proposal:abc",
    "permalink": "https://your-site/forma-projects/harbour-district/",
    "assets": { "created": 2, "updated": 0, "removed": 0 },
    "payload_hash": "e790b9…",
    "message": ""
  }
}
```

### Result statuses

`result.status` is the single most useful field. A 200 does **not** always mean content changed.

| Status | Meaning |
|---|---|
| `created` | A new project was created. |
| `updated` | An existing project was updated in place. |
| `unchanged` | The payload matched the stored version byte for byte. Nothing was rewritten. |
| `held_for_review` | The project was edited in WordPress, so the update was parked. See [Editorial review](editorial-review.md). |
| `skipped_local_edit` | The project was edited in WordPress and the policy is to keep local edits. |
| `unpublished` | Post status set to `draft`. |
| `archived` | Post status set to `private`. |
| `trashed` | The project and its assets were moved to the trash. |

Treat `unchanged`, `held_for_review` and `skipped_local_edit` as success. They are deliberate outcomes, not failures.

---

## POST /lookup

Returns the stored state of one project, for reconciliation.

**Request body**

```json
{ "source_id": "urn:adsk.forma:proposal:abc" }
```

**Response 200**

```json
{
  "success": true,
  "post_id": 84,
  "source_id": "urn:adsk.forma:proposal:abc",
  "post_status": "publish",
  "permalink": "https://your-site/forma-projects/harbour-district/",
  "payload_hash": "e790b9…",
  "last_synced": "2026-08-13T09:15:00+00:00",
  "sync_mode": "snapshot",
  "state": "published"
}
```

Returns `404` when no project matches.

The source ID travels in the body rather than the path on purpose: Autodesk identifiers are URNs containing colons, and percent-encoding a path segment would make the client and server disagree about the route string being signed.

---

## GET /status

A connectivity and version check. Makes no changes.

**Response 200**

```json
{
  "success": true,
  "plugin_version": "1.1.0",
  "schema_version": "1.0",
  "connection": "fp_a1b2c3",
  "site_url": "https://your-site",
  "projects": { "publish": 12, "draft": 3, "private": 0 },
  "server_time": 1786000000
}
```

Compare `server_time` against your own clock. Drift beyond the timestamp tolerance is the most common cause of otherwise-correct requests being rejected.

---

## Errors

Errors use the standard WordPress REST error shape:

```json
{ "code": "forma_publisher_invalid_signature", "message": "…", "data": { "status": 401 } }
```

| Status | Code | Cause and fix |
|---|---|---|
| 400 | `forma_publisher_https_required` | Plain HTTP to a non-loopback host. Use HTTPS, or disable the requirement for local testing only. |
| 400 | `rest_invalid_param` | The payload violates the canonical schema. The `data.params` member names the offending field. |
| 400 | `forma_publisher_missing_source_id` | No `project.source_id`. |
| 400 | `forma_publisher_unsupported_operation` | `operation` is not one of the five supported values. |
| 401 | `forma_publisher_missing_signature` | One or more signature headers absent. |
| 401 | `forma_publisher_invalid_signature` | Wrong secret, altered body, or a proxy re-encoding the request. |
| 401 | `forma_publisher_unknown_connection` | Key ID not registered, or the connection is disabled. |
| 401 | `forma_publisher_stale_request` | Timestamp outside tolerance. Usually clock drift. |
| 401 | `forma_publisher_invalid_timestamp` | The timestamp is not a plausible Unix time. |
| 404 | `forma_publisher_not_found` | No project matches that source ID. |
| 409 | `forma_publisher_replayed_request` | That nonce was already used. Usually a duplicate retry; safe to ignore. |
| 429 | `forma_publisher_rate_limited` | More than 60 **verified** requests per minute for this connection. |
| 429 | `forma_publisher_unverified_rate_limited` | More than 20 **failed** authentications per minute from your address. Check the secret. |

### Which errors are worth retrying

| Retry | Do not retry |
|---|---|
| `429` after a pause, `5xx`, network failures | Every `4xx` other than `429` |

A `409` means the request already succeeded. Retrying it will not help and is not an error condition.
