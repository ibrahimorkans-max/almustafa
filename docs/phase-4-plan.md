# Phase 4 — Plan: Inventory, stock ledger, production and by-products

## 1. Stock
| Table | Purpose |
|---|---|
| `stock_ledger` | **Append-only.** Every movement of a product in a godown: signed kg, movement type (receipt, production issue, production output, transfer out / in, adjustment, reversal), source (gate entry, batch, requisition, count), `reverses_id` for corrections. Never edited |
| `stock_balances` | Live balance per product × godown, maintained in the same transaction as each ledger row under a row lock (no negative stock, no lost updates). A projection: `php artisan stock:verify` recomputes it from the ledger and reports any difference |

Separate live stock for paddy, rice and by-products (husk, bran, broken) comes from the product category; screens and
reports group by it. Weights stay kg decimal strings (bcmath).

**Paddy receipt**: issuing the weighment slip of an accepted inbound lot books the **physical net weight** into the godown
chosen at unloading (payable weight after quality deductions stays the commercial figure on the sauda — both recorded).

## 2. Requisitions
Request to move stock (godown → production batch, or godown → godown): requested → approved (different person with
`requisition.approve`) → issued (posts the ledger) / rejected / cancelled. Numbered `KNL/RQ/2026-27/0001`.

## 3. Production
| Table | Purpose |
|---|---|
| `production_batches` | Milling run of one paddy variety: open → closed. Numbered. On close, the yield is **frozen** into the batch: input, output per product, outturn %, unaccounted loss |
| `production_logs` | **Append-only** hourly / product-wise logs: paddy input or product output (rice grades, husk, bran, broken) with hour slot, godown, quantity → posts the ledger. Corrections are reversing logs with a reason |
| `outturn_standards` | Expected outturn per paddy variety and output product (basis points of input) with tolerance |

## 4. Alerts and shrinkage
`alerts` (generic, reused by phase 8 anomaly detection): open → acknowledged with a note, notified in-app.
* **Outturn drift**: a closed batch whose output share differs from the variety standard beyond tolerance.
* **Unaccounted loss**: input − outputs above a threshold (default 1.5 % of input).
* **Shrinkage**: a stock count that is lower than the system balance by more than a threshold (default 0.5 %).

## 5. End-of-day stock adjustment
Physical counts per godown × product are recorded with the system balance at counting time; differences become
adjustment ledger entries **only after maker-checker approval** (`stock.adjust` makes, a different person with
`approval.decide` + `stock.adjust` approves). The posted difference is the one measured at counting time.

## 6. Sync
`production_log.recorded` joins the append-only device events (production floor tablets work offline).

## 7. Assumptions
1. Quantities only in this phase; stock valuation (cost per kg, FIFO / weighted average) comes with accounts in phase 5.
2. Stock may never go negative (configurable later if a mill needs it); an issue against missing stock is refused
   online and answered `retry` for devices (the receipt may still be in transit).
3. Physical stock follows net weight; moisture/quality deductions are commercial. Drying loss shows up as shrinkage.
4. Standard outturn defaults are typical Punjab/Haryana values for demo data only; each mill sets its own.
