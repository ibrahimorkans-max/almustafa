# Phase 6 — Summary: Sales, dispatch and gate automation

## Built

| Area | What exists |
|---|---|
| Sales saudas | Own screen (**Sales & dispatch → Sales saudas**) on the same sauda model and lifecycle as purchases: buyer, rice / by-product, rate before GST, tolerance, credit days, broker; created with `sales.order`, approved by a different person; series `S` |
| Dispatch orders | Per vehicle: sauda, godown, quantity, optional bags × size. Checks remaining sauda quantity (incl. tolerance and other open orders) and godown stock. Over the buyer's credit limit (ledger balance + draft invoices + open orders) → **waiting for a credit override** by a different person; rejection cancels. Series `DO` |
| Outbound gate flow | Arrive against an order (buyer / sauda / product taken from it) → tare → **loaded** → gross → slip → **gate pass** → exit. Slip: stock out of the order's godown (refused if short), delivery on the sauda, invoice drafted; flags for loads outside tolerance and bag-weight mismatches. Exit of a dispatch needs the gate pass |
| Tax invoices | Computed from the slip and frozen sauda rate: CGST + SGST within the state, IGST across states (place of supply from GSTIN / state), half-up rounding, round-off to the rupee, HSN, GSTINs. Posting assigns the gap-free invoice number (`KNL/INV/2026-27/0001`) and posts the sales voucher (buyer / Sales – Rice or By-products / Output CGST·SGST·IGST). PDF with amount in words and a verification code. Reversal through approval |
| Receivables | Receipts can settle invoices (oldest due first, never above the outstanding; rest on account); receivables ageing report; credit exposure falls as money comes in |
| Gate pass | Issued by `gatepass.issue` after slip + posted invoice; PDF with verification code |
| Gate hardware | `POST /api/v1/gate/barrier` (signed, logged) decides; sync events `gate_entry.plate_read` and `barrier.manual_open`; append-only hardware log on the gate entry; flags `plate_mismatch`, `barrier_manual_open` |
| Agent | ANPR camera (HTTP + simulator), boom barrier relay (TCP + simulator), display board (TCP + simulator), `GateController` (opens only on yes, fails closed, guard override with reason), CLI `gate in|out`, `manual-open`, config sections |
| Admin | New nav group **Sales & dispatch**: sales saudas, dispatch orders (vehicle arrival from the order), tax invoices (post, PDF, reversal). Gate screen: dispatch order on arrival, *Mark loaded*, *Issue gate pass*, gate pass PDF, hardware log; lab test hidden for outbound. Vouchers: receipts against invoices. Reports: receivables ageing |

## Tested
* **PostgreSQL:** 369 passed, 1 skipped. **MariaDB:** 360 passed, 10 skipped (PostgreSQL-only RLS tests). Larastan level 6 and Pint clean. **Agent:** 24 (7 new). **PWA:** 13.
* New PHP tests: sales and dispatch (10 — GST split and rounding, order limits, credit override and rejection, full
  dispatch to exit, intra-state CGST / SGST, slip refused when stock is short, tolerance and bag flags, receipts and
  receivables and credit, invoice reversal, no invoice without the mill's state code), gate devices (4 — plate match and mismatch, barrier rules and log, manual
  open + audit + append-only log, signed API and sync events), screens (6).
* Agent tests: opens on yes and queues the plate read, stays closed on no, **fails closed** offline / on server error /
  without a server, dead relay reported, manual open needs a reason and is queued, misread forwarded as read, weight
  on the display.

## Decisions (ADR-0009)
Dispatch orders between sauda and gate; credit checked where goods leave; slip moves stock and drafts the invoice;
invoice number assigned on posting; gate pass needs the posted invoice; barrier decided by the server and fails closed.

## Known gaps carried forward
* E-invoice (IRN / QR) and e-way bill (phase 8); TCS on sale of goods (phase 8).
* Freight on invoices, debit / credit notes for quality claims (phase 7 with claims).
* Cost of goods sold per invoice (valuation stays periodic).
* Seal numbers, driver identity checks, barrier tamper input (hardware, later).

## Next: Phase 7 — Claims, pricing and archive
Quality / weight claims with debit and credit notes, rate cards and price lists with permission-gated cost and margin
(hidden, never altered), and archiving with search and audited restore.
