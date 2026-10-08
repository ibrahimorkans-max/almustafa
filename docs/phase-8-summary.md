# Phase 8 — Summary: GST returns, e-invoice / e-way bill, TDS, reports and anomaly alerts

## Built

| Area | What exists |
|---|---|
| GST returns | **Accounts → GST returns** (`report.gst`): GSTR-1 B2B (by buyer GSTIN), B2CS (state × rate, intra / inter), credit notes from claim settlements (with the original invoice), HSN summary (kg, taxable, taxes); GSTR-3B outward taxable net of credit notes, nil-rated, inter-state supplies to unregistered persons. Download as portal JSON or CSV (audited); warning while the month is not locked |
| E-invoice | NIC schema 1.1 payload from the posted invoice; sandbox provider (IRN computed as the IRP does); IRN, ack no. / date and signed QR stored and printed on the invoice; cancellation within 24 h (reason codes); append-only exchange log incl. failures. Off by default (`RICEMILL_EINVOICE_ENABLED`) |
| E-way bill | From the IRN with the vehicle from the gate entry and the distance; number and validity stored and printed; gate pass can require it above ₹50,000 (setting) |
| TDS 194Q | At posting: 0.1 % (5 % without PAN) of the part of the year's purchases from a seller above ₹50 lakh, withheld from the supplier, credited to *TDS payable (194Q)*; per-party exemption toggle (accounts only); shown on the purchase bill. Off by default |
| Reports | **Administration → Reports** (`report.view`): purchase register, sales register, gate register, stock movement, production yield; on-screen (first 500 rows) and CSV (audited, formula-safe) |
| Dashboard | Vehicles inside / arrived / left, paddy received and dispatched (today, month), approvals waiting for *me*, open alerts; stock by category (stock roles); receivable / overdue and payable / due in 7 days (accounts roles only) |
| Anomaly alerts | Nightly `anomalies:scan` (03:00 IST): unusual vehicle load (robust z-score), purchase rate vs 30-day median and sales below list, after-hours weighments and manual barrier opens, repeated plate mismatches, frequent manual overrides, many releases by one approver to one party, yield drift over 3 batches. Each alert explains the numbers; de-duplicated; thresholds in config |
| Verification | Public **`/verify`** page: number + printed code → genuine / not, with key facts only; URL printed on slips, invoices and gate passes |
| Masters | Entity city and PIN code (needed for e-invoices) |

## Tested
* **PostgreSQL:** 404 passed, 1 skipped. **MariaDB:** not run at the end of this phase — the `ricemill` login was missing from the local MariaDB (environment, not code); to be re-run once it is restored. Larastan level 6 and Pint clean.
* New: GST returns (GSTR-1 tables with B2B / B2C / CDNR / HSN, 3B net of credit notes, portal JSON), e-invoice (IRN formula,
  exchange log append-only, idempotent; gate pass needs IRN then e-way bill; e-way validity stored in UTC; 24-hour and
  e-way-bill cancellation rules; B2C and missing PIN refused), TDS 194Q (no-PAN 5 %, PAN 0.1 %, exempt, disabled),
  anomalies (load outlier once and quiet on normal loads, rate and after-hours, yield drift), reports (register rows,
  audited CSV, formula defused), public verification (genuine invoice / slip / gate pass, altered code rejected, no buyer
  name shown), screens (access per role for 3 screens, GST page + JSON, IRN + e-way bill + PDF from the invoice screen,
  register CSV, dashboard money widget only for accounts).

## Fixed along the way
* **Timezones:** the app stores UTC, but IST-zoned Carbon values passed to queries or datetime columns were used as if
  they were UTC (Laravel does not convert). Fixed for dashboard day / month bounds, report date ranges, the anomaly scan
  window and e-invoice / e-way times from the provider; rule recorded in ADR-0011.
* `gstr1Json` read the entity id after switching to system context.

## Known gaps carried forward
* A real GSP adapter (needs the mill's GSP choice and credentials); GSTR-2B reconciliation and ITC.
* Returns filing itself (done on the portal / via the GSP).
* Threshold tuning for anomaly rules on live data.

## Next: Phase 9 — Hardening and deployment
Security review, backups and restore drills, secret store, monitoring, performance, Hostinger VPS deployment.
