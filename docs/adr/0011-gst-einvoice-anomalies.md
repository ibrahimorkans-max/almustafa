# ADR-0011: GST data from posted documents; e-invoice through a provider seam; explainable anomaly rules

## Status
Accepted — Phase 8.

## Context
GST returns, e-invoices and e-way bills must match the books exactly. The government systems are reached through a
GST Suvidha Provider the mill has not chosen yet. The brief asks for "AI alerts"; for a mill's owner an alert is only
useful if it says *why* it fired, and business data should not leave the server for it.

## Decision
1. **Returns are derived, not entered.** GSTR-1 (B2B, B2CS, credit notes with their original invoice, HSN summary) and
   the GSTR-3B outward summary are computed from posted invoices and posted claim credit notes for the month; the JSON
   follows the portal layout. The screen warns while the month is not locked.
2. **E-invoice behind an interface.** `EInvoiceProvider` with a sandbox implementation (IRN computed exactly as the IRP
   does: SHA-256 of seller GSTIN + financial year + document type + number). The provider is called **outside** any
   database transaction; every request and answer — including failures — is an append-only `einvoice_events` row; the
   IRN, acknowledgement, signed QR and e-way bill are copied onto the invoice and printed. Cancellation only within 24
   hours and before an e-way bill. When enabled, the gate pass requires the IRN (B2B) and, optionally, the e-way bill.
3. **TDS u/s 194Q is computed at posting**, from the seller's posted purchases of the financial year, on the part of the
   bill above the threshold (0.1 %, 5 % without PAN), withheld from the supplier and credited to *TDS payable*. Off by
   default; per-party exemption is the tax adviser's call. TCS 206C(1H) is not built (omitted from April 2025).
4. **Anomaly alerts are explainable rules**, not a model: robust z-score of a vehicle's net load against its own history,
   rate deviation from the 30-day median (and sales below list), after-hours weighments and manual barrier opens,
   repeated plate mismatches, frequent manual weight overrides, many payment releases by one approver to one party,
   and yield drift across consecutive batches. Each alert carries the rule, the numbers and the reason; findings are
   de-duplicated by a stable key; thresholds are configuration; nothing is sent outside.
5. **Public verification** of printed slips, invoices and gate passes by number + printed HMAC code, showing only the
   key facts, rate-limited.
6. **Timezone rule (found in this phase):** timestamps are stored in UTC; the query builder and Eloquent do not convert a
   Carbon's timezone, so IST day boundaries and values from outside systems are converted with `->utc()` before they
   reach a query or a datetime column. Dates (`date` columns) are IST calendar dates.

## Consequences
* Choosing a GSP is a small adapter class plus credentials; nothing else changes.
* Returns can be re-generated at any time and will always agree with the books.
* Alerts can be argued with — the owner sees the median, the z-score or the rule, not a score.
