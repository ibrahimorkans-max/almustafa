# Phase 2 — Summary: Masters, sync API, PWA offline shell

## Built

| Area | What exists |
|---|---|
| Masters | Parties (customer / vendor / broker / farmer / transporter roles, GSTIN + PAN encrypted with blind indexes, GSTIN check-digit validation, credit limit in paise), bank accounts, drivers, vehicles (Indian + BH-series normalisation, blocked flag for the boom barrier), products (category, variety, HSN, GST rate, brand, sale unit, bag weight), brands, godowns / silos, GST rates (basis points). All entity-scoped + RLS, audited, optimistically locked, `archived_at` hidden by default |
| Values | `Weight` (kg base, bcmath, quintal/ton display), `Money` (paise, Indian grouping), `IndianIds` (GSTIN checksum, PAN, IFSC, vehicle no., mobile), `BlindIndex` |
| Bank accounts | Only via maker-checker (`party.bank_account.change`): add / change / deactivate; checker needs `bank.view`; number + UPI encrypted at rest **and inside the pending request**; masked everywhere else |
| Sync API | `/api/v1/sync/bootstrap · push · pull · wipe-ack`; idempotent events, per-device ordering, gap-free commit-ordered change feed, version check + last-write-wins with conflict log, supervisor review queue, online-only hold, clock-skew clamp, offline limit, remote wipe instruction + acknowledgement |
| OpenAPI | [docs/api/openapi.yaml](api/openapi.yaml) (auth + sync); a test fails if routes or sync statuses drift from it |
| Admin | Masters menu (Parties with bank-account requests, Vehicles, Drivers, Products, Brands, Godowns, Tax rates) and the Sync review queue (apply device data / keep server data, with a note) |
| PWA | `/pwa/`: installable, app shell cached by a service worker, IndexedDB records + outbox, Online / Offline / Syncing badge with unsynced count, sign-in (device-bound tokens, 2FA step), vehicles list + offline registration, messages for conflicts/rejections, offline-too-long lock, remote wipe, safe user switching. Strict CSP, no inline code, no build step |
| Access | New permission `sync.review` (Owner, Supervisor, Super Admin); Supervisor template now has `master.manage`; gate operators may register vehicles/drivers (`gate.entry`). `access:sync` grants newly released permissions to existing roles per template (audited) |
| Data | Demo masters for both demo mills (paddy varieties, basmati brand, husk/bran/broken, silos, farmer/trader/customer/broker/transporter, trucks incl. a blocked one) |

## Tested
* **PostgreSQL:** 213 passed, 1 skipped (1387 assertions). **MariaDB:** 204 passed, 10 skipped.
* **PWA sync engine:** 13 `node --test` tests (offline queueing, ordered batched upload, idempotent resend after a
  dropped connection, local edits not overwritten by pull, archive removal, rejection/hold handling, remote wipe,
  401 keeps work, offline lock, per-account outbox).
* Sync API (20): create with client UUID, idempotency, ordering, validation/unknown/forbidden, version apply,
  client-won / server-won conflicts, clock clamp, duplicate / archived / too-old → review, pending_online,
  paging + dedupe, archive op, field masking per viewer, cross-entity id guessing, gap-free feed, wipe flow,
  append-only sync tables.
* Masters (9 + 8): encryption + blind index, GSTIN uniqueness per entity, masked audit values, archived hidden,
  optimistic lock, bank maker-checker (no clear text in request/audit, checker rules, change/deactivate, one primary),
  catalog upgrade; admin screens per role, gate operator vehicle registration, party form (GSTIN check digit, paise),
  bank request from the UI, review queue apply/dismiss.
* Authorization matrix extended: sync read/write per role for every role; all sync endpoints reject anonymous calls.
* Contract: OpenAPI ↔ routes ↔ statuses.
* Manual browser check: PWA sign-in, online registration, **server stopped → registration offline (badge "Offline · 1
  unsynced") → server back → sync → uploaded**, audited and attributed on the server.

## Fixed along the way
* API login now refuses accounts with a temporary password (`password_change_required`) instead of letting them sync
  with no permissions.
* Remote wipe no longer revokes sessions immediately (the device could never learn it had to wipe).
* Shared append-only trigger now names the actual table in its error.

## Next: Phase 3 — Procurement, sauda, gate entry, weighbridge, lab (with device simulators)
1. Requirements broadcast, quotes, negotiation, sauda (deal) lifecycle with full timestamps, paddy purchase terms.
2. Gate entry and weighments (gross / tare / net, stable-weight detection, scale id, photo hash, manual override only by
   Super Admin with reason + approval, repeat-tare tolerance flag) as **append-only** sync handlers.
3. Lab tests (moisture, purity, grade) with accept/reject before unloading, deduction rules, weighment slip.
4. Python gate agent: driver interfaces + simulators (weighbridge RS-232/TCP, camera), local queue, same sync API,
   signed requests; calibration / sealing certificates with expiry reminders.
