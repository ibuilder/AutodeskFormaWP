# Editorial review and local edit protection

Snapshot publishing has an inherent conflict. An editor improves a project page — fixes the summary, adds context, corrects a title — and the next synchronization overwrites that work. Nobody is told. The page silently reverts, and the only trace is a revision nobody thinks to check.

This is the failure mode most likely to make people distrust an automated publishing pipeline, so the plugin treats it as a first-class case rather than an edge case.

## How a local edit is detected

At the end of every successful write, the plugin records the post's modification time in `_forma_synced_modified`. That value is the state the plugin produced.

When the next update arrives, the current modification time is compared to the recorded one:

- **Equal** — nothing touched the post since the plugin last wrote it. The update applies normally.
- **Different** — a human edited the post. A policy decides what happens.

Two consequences worth knowing:

- A project with **no** recorded time predates this feature and is treated as unedited. Upgrading the plugin therefore does not park every project at once.
- Revisions and autosaves do not advance the parent post's modification time, so drafting in the editor does not trigger a false conflict. Saving does.

## Policies

Set under **Forma → Settings → When a project was edited here**.

| Policy | Behaviour | Ingest result |
|---|---|---|
| **Hold for review** (default) | The incoming payload is parked against the post. The live page keeps the local edits. | `held_for_review` |
| **Keep the local edits** | The update is discarded outright. | `skipped_local_edit` |
| **Overwrite** | The update applies and the local edits are lost. | `updated` |

All three return HTTP 200. None is an error — each is a deliberate outcome, and the backend records the status rather than retrying.

Choose **hold** when editors add value to pages. Choose **overwrite** only when Forma is genuinely the sole source of truth and WordPress is a pure mirror.

## The review queue

**Forma → Review** lists everything needing a decision, and the menu carries a count badge when anything is waiting.

### Updates held because the project was edited here

Each row shows the current project, the incoming title, and how long it has been waiting.

| Action | Effect |
|---|---|
| **Apply update** | Applies the parked payload, discarding the local edits. The post is then in sync again. |
| **Keep local edits** | Discards the parked payload and records the current version as the agreed state, so the next update is not immediately held again for the same reason. |

That last detail matters. Without it, keeping a local edit would park every subsequent update forever, and the queue would fill with the same project.

### Projects awaiting approval

When **Editorial approval** is enabled, newly published projects arrive as `pending` instead of going live. Approving publishes the post and records the current state as synced.

Approval applies to **new** projects only. Updates to already-published projects follow the conflict policy instead — otherwise every routine refresh would demand a click.

## Applying a held update

Applying deliberately skips the conflict check. This is not an oversight: the conflict is derived from the modification time, which is still divergent at the moment you click. Clearing the hold alone would let the same update be parked again immediately.

The operator has explicitly chosen to apply the update, so the check is bypassed for that one write, after which the recorded sync time is refreshed and the project is clean again.

## What is recorded

Every review action is written to the audit log with its operation and the affected project:

| Operation | Meaning |
|---|---|
| `review_applied` | A held update was applied |
| `review_discarded` | A held update was discarded, local version kept |
| `review_approved` | A pending project was published |

## Operator overview

**Forma → Overview** answers the question "is this working?" on one screen:

- Whether an enabled connection exists at all, with a warning when none does
- The ingest endpoint URL, ready to copy into the backend
- When a publish was last accepted
- Whether a scheduled refresh is configured, and when it next runs
- Whether WP-Cron is enabled, with a note that it only fires on site traffic
- The active local edit policy
- Counts: published, awaiting review, drafts, enabled connections, recent failures
- The most recent failure message, if any
- The last ten log entries

## Interaction with sync mode

A scheduled refresh runs unattended, which is exactly when silent overwriting would do the most damage. The conflict policy applies identically to refresh-driven updates, so an unattended refresh cannot quietly discard editorial work while nobody is watching.

The publishing template a project was first published with is also re-applied on every refresh, so a refresh can never widen what a page exposes.
