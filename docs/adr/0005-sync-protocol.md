# ADR-0005: One event-based sync protocol for PWA, Flutter and the gate agent

**Status:** accepted (phase 2) · Contract: [docs/api/openapi.yaml](../api/openapi.yaml)

## Decision
* **Push** — devices queue events locally (`event_id` UUID, `client_seq`, `occurred_at`, `type`, `record_id`,
  `base_version`, `payload`). The server stores every event in append-only `sync_events`, which is also the
  idempotency table: a re-sent `event_id` returns the stored result and changes nothing. `client_seq` must increase
  per device. Each event runs in its own transaction so one bad event cannot block the queue; an unexpected server
  error stores nothing and answers `retry`.
* **Pull** — `sync_changes` is a per-entity feed whose `seq` comes from a head row locked inside the writing
  transaction. Sequence numbers therefore become visible in commit order with no gaps, so a cursor never skips a
  late-committing change (the classic auto-increment cursor bug). Pull returns the latest state per record,
  serialised for the viewer (e.g. PAN masked without `bank.view`); archived records come back as `op: archive`.
* **Conflicts** — master data: equal `base_version` → apply; otherwise last write wins on event time (device clocks
  ahead of the server are clamped), and the losing side is kept in `sync_conflicts`. Unmergeable cases (duplicate
  unique value, record archived meanwhile, event older than `max_offline_hours`) go to the supervisor review queue
  (`sync.review`), where a supervisor applies or dismisses with a note (audited).
* **Append-only types** (weighments, stock, ledger — phases 3–5) register handlers that only insert, so they cannot
  conflict. Online-only actions (large payments, credit approval) are held as `pending_online`.
* **Remote wipe** — a wipe-pending device stays authenticated but every call answers `403 wipe_required` until it
  calls `/sync/wipe-ack`; then its sessions end.
* **Offline limit** — `max_offline_hours` (72 by default): the client locks itself and requires an online sign-in;
  older events go to review on the server.

## Consequences
* Same contract for every client; the agent (phase 3) and Flutter (phase 10) reuse the endpoints unchanged.
* Event authorship is the account that pushes; the PWA only pushes events created by the signed-in account and will
  not let another user sign in on a device holding someone else's unsynced work.
* Field-level merge is not attempted for masters (record-level LWW + full conflict log) — simpler and auditable;
  revisit if real usage shows frequent concurrent edits of the same master.
