# Phase 8 — Plan: GST returns, e-invoice / e-way bill, TDS, reports and anomaly alerts

## 1. GST returns (data, not filing)
Built straight from posted documents — never from typed figures — for a month (`report.gst`):
* **GSTR-1**: B2B invoices (buyer GSTIN, invoice no / date / value, place of supply, rate, taxable, IGST / CGST / SGST),
  B2C (state-wise, rate-wise totals for unregistered buyers), credit notes (from claim settlements, linked to the original
  invoice), and the **HSN summary** (HSN, quantity in kg, taxable, taxes). Exported as CSV per table and as the GST
  portal's JSON layout for upload through the offline tool / GSP.
* **GSTR-3B summary**: outward taxable supplies and tax (3.1), inter-state supplies to unregistered persons by state (3.2),
  and nil / exempt supplies (e.g. 0 % paddy / loose rice). Input tax credit stays manual in this phase (purchases of
  paddy carry no GST).
* Locked months (phase 5) are what returns should be filed from; the screen warns when the month is still open.

## 2. E-invoice (IRN) and e-way bill
* **Payload builders** for the NIC e-invoice schema (v1.1 JSON) and the e-way bill (Part A + Part B with the vehicle from
  the gate entry), from the posted invoice and its frozen snapshot.
* **Provider interface** (`EInvoiceProvider`) with a **sandbox / fake provider** for development and tests; a real GSP
  (GST Suvidha Provider) adapter is a small class using credentials from `.env` once the mill chooses its GSP.
* IRN, acknowledgement no. and date, signed QR and e-way bill no. / validity are stored on the invoice (append-only
  event history); the invoice PDF prints the IRN and QR. Cancellation of an IRN (within 24 h) is a request with a reason.
* Rules: e-invoice when the mill is notified (turnover threshold, setting), only for B2B; e-way bill when the
  consignment value exceeds ₹50,000 (configurable); the gate pass can be made to require the e-way bill (setting).

## 3. TDS on purchases (s. 194Q)
* When the setting is on (buyer's previous-year turnover > ₹10 crore), purchase bills deduct **0.1 %** on the part of
  the year's purchases from a seller above **₹50 lakh** (5 % without a PAN, s. 206AA). The deduction is computed on the
  bill (shown before posting), posted to *TDS payable (194Q)* and reduces what the supplier is paid. Per-party exemption
  flag for sellers outside 194Q (e.g. agriculturists whose produce is exempt under the mill's tax advice).
* TCS under s. 206C(1H) is not built (omitted from 1 April 2025).

## 4. Reports and dashboard
* **Dashboard** (`dashboard.view`): today's vehicles inside / arrived / exited, paddy received and rice dispatched today
  and month-to-date, stock by category, open requisitions and batches, receivables and payables due, open alerts and
  approvals waiting for me. Confidential figures follow the same rule as phase 7 (hidden, never altered).
* **Reports** (`report.view`, CSV export): purchase register, sales register, gate register, stock movement by product /
  godown / period, production yield by batch and variety, party ledger summary. Every export is audited.

## 5. Anomaly alerts (explainable, statistical)
A nightly `anomalies:scan` raises alerts through the phase 4 alert framework, each with the rule, the numbers and why it
fired — no black box and no data leaves the server:
* net weight far from the vehicle's usual load (robust z-score on the last 20 trips);
* purchase rate far from the 30-day median for the product (and sales rate below the list rate by more than a limit);
* weighments or barrier manual opens outside working hours; repeated plate mismatches for a vehicle;
* a user approving unusually many requests for the same counter-party; frequent manual weight overrides;
* batch yields drifting over several batches of the same variety.
Thresholds live in `config/ricemill.php`; each alert type can be switched off.

## 6. Public verification page
`/verify/{code}` shows, for a weighment slip, tax invoice or gate pass verification code, only that the document exists
and its key facts (number, date, vehicle, weight / amount) — enough for a buyer or checkpost to detect a forged printout,
no other data. Rate-limited.

## Tests (planned)
GSTR-1 tables and HSN summary from fixtures (B2B / B2C, IGST / CGST+SGST, credit notes), 3B totals, JSON layout; e-invoice
and e-way payloads (field values, rounding), fake provider flow, IRN stored and printed, cancellation window; 194Q threshold
crossing within one bill, no-PAN rate, exemption; dashboard figures and confidentiality; report exports audited; each
anomaly rule fires on a constructed case and stays quiet on normal data; verification page; screens per role; both
databases.

## Assumptions (change if wrong)
* Filing itself is done on the GST portal / through the mill's GSP; this phase produces correct data and payloads.
* 194Q is **off by default** (it applies only if last year's turnover exceeded ₹10 crore); exemptions are a per-party
  decision for the mill's tax adviser.
* Working hours for the after-hours rule: 06:00–22:00 IST (configurable).
