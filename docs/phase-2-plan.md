# Phase 2 — Plan: Masters, sync API, PWA offline shell

## 1. Masters (all entity-scoped, UUID, `version`, `created_by`, `archived_at`, audited)

| Table | Key fields |
|---|---|
| `parties` | code, name, legal name; role flags `is_customer / is_vendor / is_broker / is_farmer / is_transporter`; contact, address, `state_code`, pincode; **GSTIN, PAN encrypted + HMAC blind index** (unique per entity, searchable without decrypting); GST registration type; credit limit (paise) and days; `login_account_id` (broker/agent portal link); status active/blocked |
| `party_bank_accounts` | holder, **account number encrypted** (+ last 4 shown), IFSC, bank, branch, UPI (encrypted), primary flag. **Created/changed only through maker-checker** (classic payment-fraud vector); numbers masked unless `bank.view` |
| `drivers` | name, phone, licence no + validity, transporter party |
| `vehicles` | registration no (normalised, Indian + BH-series format), type, owner party, default driver, capacity kg, **blocked + reason** (ANPR blacklist in phase 6) |
| `brands` | code, name |
| `tax_rates` | name, GST rate and cess in basis points (500 = 5 %) |
| `products` | code, name, category (paddy / rice / by-product / packing / consumable / other), variety, HSN, tax rate, brand, sale unit (kg / quintal / ton / bag), bag weight, stock item flag |
| `godowns` | code, name, type (godown / silo / yard / bin), capacity kg |

Units: weights `decimal(15,3)` **kg** handled as strings with bcmath (`Weight` value object converts kg ↔ quintal ↔ ton);
money integer **paise** (`Money`). No floats anywhere.

## 2. Sync API — one contract for PWA, Flutter and the gate agent

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/sync/bootstrap` | server time, entity, permissions, max offline hours, supported event types, current cursor |
| `POST /api/v1/sync/push` | ordered batch (≤ 500) of client events; each event idempotent by its client UUID |
| `GET /api/v1/sync/pull?cursor=&limit=` | changes after a cursor, records serialised with field permissions; archived → `archive` op |
| `POST /api/v1/sync/wipe-ack` | device confirms remote wipe; device marked wiped, sessions revoked |

Event: `{event_id (uuid), client_seq, occurred_at, type ('vehicle.upsert' …), record_id, base_version, payload}`.

* **Idempotency:** `sync_events.event_id` unique — a replayed event returns the stored result.
* **Ordering:** `client_seq` strictly increasing per device; gaps are fine, going backwards is rejected.
* **Masters:** `base_version == current` → apply. Otherwise **last-write-wins** on event time (clamped to receipt time)
  and the loser's values go to `sync_conflicts` (resolution `client_won` / `server_won`).
* **Unmergeable** (e.g. duplicate registration no / GSTIN, record archived meanwhile, event older than the offline
  limit) → `needs_review` in the **supervisor review queue** (Filament, permission `sync.review`).
* **Ledger / stock / weighments** (phases 3–5) plug in as append-only handlers: no overwrite conflicts by design.
* **Offline limits:** handlers declare whether they may be created offline; online-only ones (large payments,
  credit approvals) are held as `pending_online` until confirmed online. `max_offline_hours` forces re-login.
* **Pull cursor:** per-entity gap-free sequence assigned under a row lock inside the writing transaction, so a
  cursor can never skip a change that commits late.
* **Remote wipe:** a wipe-pending device gets `403 wipe_required` on every sync call, wipes, then acks.
* **OpenAPI 3.1** document for auth + sync at `docs/api/openapi.yaml` (a test keeps it in step with the routes).

## 3. PWA offline shell (`/app` path of the Laravel site)

Plain ES modules, no build step: service worker (app-shell cache), IndexedDB stores (records, outbox, meta),
sync engine (push then pull, retry with backoff, Background Sync where available), **Online / Offline / Syncing
badge with unsynced count**, login (device-bound tokens), vehicles list + offline create as the first screen
(the gate needs it). The sync engine is storage-agnostic and unit-tested with `node --test`.

## 4. Assumptions
1. Bank-account changes always need maker-checker (not in the brief, but standard fraud control).
2. New permissions this phase: `sync.review`. When a release adds a permission, `access:sync` grants it to existing
   entity roles whose template includes it (audited as a catalog upgrade) — otherwise new features would be unusable
   until an approval round.
3. Browser storage cannot be truly encrypted against a user with device access; the PWA keeps only what the gate
   needs, wipes on logout / remote wipe, and is protected by a strict CSP (no inline scripts). The Flutter app (phase 10)
   uses SQLCipher.
4. Archiving job and archive search are phase 7; `archived_at` exists now and archived rows never sync to devices.
