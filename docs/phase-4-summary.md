# Phase 4 — Summary: Inventory, stock ledger, production and by-products

## Built

| Area | What exists |
|---|---|
| Stock ledger | `stock_ledger` append-only (DB triggers), signed kg per product × godown with movement type and source; `stock_balances` kept in the same transaction under a row lock; negative stock refused; corrections are reversal rows; transfers are two rows in one transaction; `stock:verify` (scheduled 02:30 IST) recomputes balances from the ledger |
| Paddy receipt | Unloading asks for the godown (screen and `gate_entry.event` sync payload); the slip of an accepted inbound lot books the **physical net weight** there (payable weight stays the commercial figure on the sauda). No godown → entry flagged `no_godown`, nothing guessed |
| Requisitions | Paddy to a batch or stock to another godown: requested → approved by a different *person* (`requisition.approve`) → issued (stock moves; refused if short) / rejected / cancelled. Numbered `KNL/RQ/2026-27/0001` |
| Production | Batches per paddy variety (`KNL/B/…`), open → closed. Hourly input / output logs move stock at once (paddy out; rice, broken, bran, husk in). Corrections are reversing logs with a reason. Totals are a projection; at close the yield is frozen into the batch with the standards used |
| Yield & standards | Outturn per output product in basis points of paddy input, against per-variety standards with tolerance; unaccounted loss = input − all outputs |
| Stock counts | Count captures the books figure at counting time; any difference goes to a `stock.adjustment` maker-checker request and is posted as found; matching counts are applied at once; rejected requests mark the count rejected (new `HandlesRejection` hook on approval actions) |
| Alerts | Generic `alerts` (open → acknowledged with a note, never deleted), in-app notification to holders of the responsible permission: outturn drift (warning), unaccounted loss > 1.5 % (critical), shrinkage > 0.5 % (critical). Thresholds in `config/ricemill.php` |
| Sync | New append-only event `production_log.recorded` (`retry` / `insufficient_stock` while the godown lacks the paddy, idempotent by `record_id`); new read-only feed `production_batch` |
| Admin | New nav group **Stock & production**: alerts (badge with open count), stock in hand (grouped paddy / rice / by-products, zero rows hidden), stock ledger (filters, linked from each balance), requisitions (create + approve / reject / issue / cancel), milling batches (open, log input / output, correct, close, live and frozen yield), outturn standards (edited in %), stock counts (record + link to the approval). Gate screen: godown on unloading, "Unloaded into" shown |
| Demo data | Outturn standards for both demo paddies (head rice 64 % / 67 %, broken 5 %, bran 7 %, husk 21 %) |

## Tested
* **PostgreSQL:** 307 passed, 1 skipped (2052 assertions). **MariaDB:** 298 passed, 10 skipped (PostgreSQL-only RLS tests; 2011 assertions).
* **PWA engine:** 13. **Agent:** 17 (unchanged, still green). Larastan level 6 and Pint clean.
* New: stock ledger (8: balance = Σ ledger, no negative stock, zero / non-stock refused, transfer atomicity, single
  reversal, DB-level append-only, tamper detection + `stock:verify` exit code, entity isolation), production (8: numbering,
  stock moves with logs, overdraw refused, log validation, reversal flow, frozen yield + both alerts + notifications,
  no alert within standards, permissions), requisitions and counts (7), gate receipt and sync (4: net weight into the
  chosen godown, `no_godown` flag, retry → applied → idempotent resend, batch feed), screens (12: every new page per role,
  full batch run through the UI, count, standards in %, alert acknowledgement), queue context (2).
* The Phase 3 gate screen test now also checks that unloading needs a godown and that the slip books the stock.

## Fixed along the way
* **Context wiped by inline jobs:** the queue hooks that clear tenant and actor context also fired for jobs on the `sync`
  connection, which run inside the current request — sending an in-app notification then left the rest of the request
  without an entity (fails closed: rows vanish, writes refused). The reset now skips the `sync` connection. This matters
  for the shared-hosting pilot, which may run without a worker. Regression-tested.
* Login accounts could not receive database notifications (`Notifiable` was missing); certificate reminders would have
  failed as soon as someone was due one.
* A generated unique-index name exceeded MariaDB's 64-character limit; named explicitly.
* Freshly created masters read `status` / `is_stock_item` as null until reloaded; model defaults now mirror the column
  defaults.

## Decisions (ADR-0007)
Append-only ledger + locked balance projection; gate books net (physical) weight; production logs move stock; yield and
standards frozen at close; count differences via maker-checker, posted as found; offline overdraw → `retry`.

## Known gaps carried forward
* No valuation yet (quantities only) — weighted-average cost per godown comes with accounts (phase 5).
* Dispatch out of stock (phase 6), packing material consumption per bag (phase 6, with bagging).
* Blind counts (counter does not see the books figure) as an option; capacity warnings per godown.
* Machine counters / bagging scales feeding production logs directly (hardware, later).

## Next: Phase 5 — Accounts
Double-entry ledger (append-only, reversal vouchers), chart of accounts, purchase bills from saudas + slips, payments with
maker-checker and bank details from the approved bank account, cash / bank books, period lock, stock valuation.
