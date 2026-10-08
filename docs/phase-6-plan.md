# Phase 6 — Plan: Sales, dispatch and gate automation (ANPR, boom barrier, display)

Goal: rice and by-products leave the mill only against a confirmed sale, loaded from a known godown, weighed on the
weighbridge, billed with correct GST and recorded in stock and the books — with the gate hardware (number-plate camera,
boom barrier, display board) driven by the same agent that already reads the weighbridge.

## 1. Sales deals and orders
* **Sales saudas** reuse the sauda model (`direction = sale`, series `S`): buyer, product, quantity, rate, tolerance,
  delivery window, credit days, broker. Same lifecycle and maker-checker as purchases (`sales.order` to create,
  `deal.approve` to confirm); approved terms are frozen.
* **Credit check** at each dispatch order (where goods actually leave): buyer's outstanding receivables + this load must stay within
  the party's credit limit; over the limit → blocked unless an `approval.decide` holder approves an override
  (maker-checker, reason recorded).
* **Dispatch orders** (`dispatch.manage`): sauda, quantity for this vehicle, godown to load from, bag count / bag size
  for packed rice. Numbered `KNL/DO/2026-27/0001`.

## 2. Outbound gate flow (already modelled in phase 3)
arrived (against a dispatch order) → tare → **loaded** (from the order's godown, bags counted) → gross → slip → **gate
pass** → exited.
* Net = gross − tare must be within the order quantity ± tolerance (and the bag count × bag weight check for packed
  rice); outside → flag and require a supervisor note.
* **Slip for outbound** books the **dispatch** in the stock ledger (net kg out of the loading godown; refused if short —
  no negative stock) and records the delivery on the sales sauda.
* **Gate pass** (`gatepass.issue`): issued only after the slip and the sales bill exist; printed PDF with an HMAC
  verification code (same scheme as the weighment slip). The exit is refused for an outbound vehicle without a gate pass.

## 3. Sales bills (tax invoices) into the books
* Drafted from the outbound slip + frozen sauda terms: taxable value = net kg × rate, GST from the product's tax rate —
  **CGST + SGST** when the buyer's state equals the mill's state, **IGST** otherwise (place of supply from the buyer's
  GSTIN / state code) — rounded per line, invoice round-off to the rupee. HSN, GSTINs, place of supply and amount in
  words on the invoice PDF.
* Posting (by accounts) creates a **sales voucher**: Dr buyer, Cr Sales – Rice / By-products, Cr Output CGST / SGST /
  IGST ledgers (new system ledgers under Duties & Taxes). Invoice numbers are gap-free per financial year (GST rule).
* Cost of goods sold is not posted per invoice (periodic stock valuation from phase 5 stays the source).
* Receivables: receipts can now be allocated to sales bills (mirror of payments → purchase bills); receivables ageing
  report; due-date reminders in-app.

## 4. Agent: ANPR, boom barrier, display board
* **ANPR camera** driver (HTTP snapshot + plate result from the camera's built-in recognition, with simulator):
  plate read at the gate → `gate_entry.plate_read` event with photo hash and confidence. The server matches it to the
  open gate entry / expected vehicle; a mismatch between the typed registration and the plate read is flagged
  (`plate_mismatch`), never silently corrected.
* **Boom barrier** driver (relay over TCP / serial, with simulator): opened only on a server instruction —
  entry for a registered arrival, exit only for an inbound vehicle with a slip or an outbound vehicle with a gate pass.
  Every open is an audited event; manual open by a guard needs a reason and is flagged.
* **Display board** driver (TCP text protocol, with simulator): shows registration number and weight at the weighbridge
  and "PROCEED" / "STOP" at the barrier.
* All three use the existing signed agent channel and offline queue; the barrier fails **closed** when the server
  cannot be reached (manual override with reason, synced later).

## 5. Screens
Sales saudas (with sale direction), dispatch orders, outbound gate steps (load, gate pass), sales bills (review, post,
PDF), receipts against bills, receivables ageing, credit-limit overrides, gate hardware events.

## Tests (planned)
Sales sauda lifecycle and frozen terms; credit limit block + override by a different person; dispatch: stock out of the
right godown, refused when short, tolerance flags; gate pass required for exit; GST split (intra vs inter state,
rounding, round-off); sales voucher lines; invoice numbering; receivables allocation and ageing; ANPR mismatch flag;
barrier opens only on rules and fails closed; agent simulators end-to-end; screens per role; both databases.

## Assumptions (change if wrong)
* Rice and by-products are sold by weight (packed rice also carries bag count / size for checks).
* GST rates come from each product's tax-rate master (e.g. 5 % packed & labelled, 0 % loose — the mill sets them).
* E-invoice (IRN) and e-way bill generation stay in phase 8; the invoice carries the fields they will need.
* Freight is out of scope for the bill in this phase (buyer's account or a later debit note).
