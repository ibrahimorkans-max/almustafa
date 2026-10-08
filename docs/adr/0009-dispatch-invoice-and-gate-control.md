# ADR-0009: Goods leave only with a slip, a posted tax invoice and a gate pass; the barrier fails closed

## Status
Accepted — Phase 6.

## Context
Leakage on the sales side is goods leaving without being billed (or billed lighter than loaded), selling beyond a
buyer's credit, and trucks waved through the gate. GST law also requires a tax invoice to accompany goods in transit,
with gap-free invoice numbers.

## Decision
1. **Dispatch orders** sit between the sales sauda and the gate: buyer, product, godown and quantity are fixed there; a
   vehicle arrives *against* an order. An order whose value (incl. GST) would take the buyer over the credit limit
   waits for a **credit override** by a different person (`approval.decide` + `sales.order`); rejection cancels it.
   Exposure = debit balance of the buyer's ledger + draft invoices + open orders (no limit set = not checked).
2. **The outbound slip moves the stock**: net kg out of the order's godown in the same transaction (refused if short —
   the slip is then not issued), the delivery booked on the sales sauda, the tax invoice drafted. A load outside the
   order quantity ± the sauda tolerance, or far from bags × bag size, is flagged, never adjusted.
3. **Tax invoices are computed**: taxable = net kg × frozen sauda rate; CGST + SGST (each rounded half-up) when the
   place of supply (buyer GSTIN state, else party state) equals the mill's state, IGST otherwise; total rounded to
   the rupee. The **invoice number is assigned on posting** from a per-financial-year series in the same transaction,
   so it is gap-free. Posting creates the sales voucher (buyer Dr; sales and output-GST ledgers Cr).
4. **Gate pass** only after the slip **and** the posted invoice; an outbound dispatch cannot record its exit without it.
   Gate pass and invoice PDFs carry an HMAC verification code over their frozen data.
5. **Gate hardware**: the server decides, the agent executes. The barrier opens only on an explicit "open" from
   `POST /gate/barrier` (in: registered arrival not blocked; out: slip, or gate pass for a dispatch); offline, on an
   error or with a dead relay it stays **closed**. A guard's manual open needs a reason, is synced as a fact, audited
   and flags the vehicle. Plate reads are forwarded as read; a mismatch with the typed registration is flagged,
   never corrected. The hardware log is append-only.

## Consequences
* A truck at the gate can be held up by accounts not having posted the invoice — deliberate; posting is one click.
* Credit control is enforced where goods leave; confirming a sales sauda over the limit is allowed (it is only a
  promise), the dispatch is what waits.
* Cost of goods sold is not posted per invoice; stock value stays periodic (ADR-0008).
* E-invoice (IRN / QR) and e-way bill generation remain for phase 8; the invoice already carries the needed fields.
