# ADR-0007: Append-only stock ledger with locked balances; production logs move stock

## Status
Accepted — Phase 4.

## Context
Stock must be provably true: every kilogram in a godown has to trace back to a gate slip, a production log, an approved
requisition or an approved count adjustment. Several people and devices move stock at the same time (stores issuing
paddy, the plant tablet logging output, the gate booking receipts), and some of them work offline.

## Decision
1. **`stock_ledger` is append-only** (DB triggers reject UPDATE/DELETE, as for the audit log). Each row is a signed
   quantity for one product × godown, with a movement type and a link to its source (gate entry, production log,
   requisition, stock count). A correction is a `reversal` row pointing at the original, never an edit.
2. **`stock_balances` is a projection** updated in the same transaction as the ledger row, after taking a row lock on
   the product × godown balance (`SELECT … FOR UPDATE`). Two concurrent issues therefore serialise, and a movement that
   would make the balance negative is refused. `stock:verify` (nightly) recomputes every balance from the ledger.
3. **`StockLedger` is the only writer.** Gate, production, requisitions and count adjustments all post through it.
4. **The gate books the physical net weight**, not the payable weight: deductions change what is paid, not what is in the
   godown. The godown is chosen when unloading; a slip without one is flagged `no_godown` rather than guessed.
5. **Production logs are facts that move stock.** An input log issues paddy, an output log receives rice or by-products,
   both in the log's transaction. Batch totals are a projection; the yield (outturn per product against the standard,
   unaccounted loss) is computed by a pure calculator and **frozen into the batch at close**, together with the standards
   used, so later changes to standards do not rewrite history.
6. **Count differences need maker-checker.** A count captures the books figure at counting time; the difference goes to
   a `stock.adjustment` approval (checker: different person with `approval.decide` + `stock.adjust`) and is posted as
   found, so stock that moved between counting and approval is not undone. A count well below the books raises a
   shrinkage alert immediately, whatever the approval outcome.
7. **Offline devices get `retry`** (reason `insufficient_stock`) when a log would overdraw a godown, because the
   receipt it depends on is usually still on another device. Negative stock is never stored.

## Consequences
* Stock can always be explained row by row, and the balance can be re-derived and checked.
* No valuation yet: quantities only. Valuation (weighted average per godown) comes with accounts in phase 5, on top of
  the same ledger.
* A wrong output that has already been dispatched cannot be reversed without stock; that is deliberate (the dispatch
  would have to be reversed first).
* Approval actions may now implement `HandlesRejection` so a subject (here the stock count) follows a rejected or
  withdrawn request.
