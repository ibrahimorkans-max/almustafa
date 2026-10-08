# Phase 7 — Summary: Claims, pricing and archive

## Built

| Area | What exists |
|---|---|
| Claims | Against a posted sales invoice (buyer claims) or purchase bill (claim on the supplier): type, description, amount, photo / document evidence (content hashes), optional transporter. Raised and worked by `claim.manage` (notes, evidence, withdraw); a **different person** with `claim.approve` fixes the settlement (≤ claimed, ≤ still claimable on the document) or rejects. Numbered `KNL/CLM/2026-27/0001`. History is append-only |
| Notes | Accounts posts the settlement: **credit note** (Sales claims & allowances + GST reversed in the invoice's CGST / SGST / IGST proportion, buyer Cr) or **debit note** (supplier Dr, Purchase claims recovered Cr). Outstanding, ageing and credit exposure follow. Reversing a note (maker-checker) reopens the claim as approved; invoices / bills with claims cannot be reversed meanwhile |
| Price lists | Lists with validity; product rates as append-only versions; **rate changes via maker-checker**; current rates + full history on screen |
| Confidential pricing | Cost / qtl (weighted average), margin, margin % and markup-based suggested rate — shown only with `pricing.margin.view` on price lists, sales saudas and tax invoices; absent (not altered) for everyone else. Markups per product category (`pricing.markup.set`) |
| Sales saudas | Show the current list rate and flag a deal below it |
| Archive | Monthly `archive:run` (dry-run option, retention 24 months, configurable) marks closed saudas, gate entries, batches, requisitions and claims as archived — never deletes. Working lists hide archived rows (filter to show). **Archive** page: search by number / vehicle / party (`archive.search`), restore with a reason (`archive.restore`, audited) |
| Admin | New nav group **Claims & pricing** (claims, price lists, markups); **Administration → Archive** |

## Tested
* **PostgreSQL:** 386 passed, 1 skipped. **MariaDB:** 377 passed, 10 skipped (PostgreSQL-only RLS tests). Larastan level 6 and Pint clean.
* New: claims (5 — credit note with IGST reversal and its voucher lines, CGST / SGST split, debit note lowers what the
  mill pays, decider ≠ raiser + limits + evidence must exist + append-only history, note reversal reopens the claim and
  blocks invoice reversal), pricing (2 — versioned rates via approval + append-only, margin only with permission and
  real figures, markup permission), archive (2 — selection + dry run + command + search + restore with reason and audit,
  permissions), screens (8 — access per role for 4 screens, claim flow with photo upload, price list + rate request,
  **two roles viewing the same invoice: same figures, margin section only for the owner**, archive search + restore).

## Decisions (ADR-0010)
Claims settle only by traceable notes after a different person approves; GST reversed in proportion; rates versioned and
approved; confidential figures absent rather than altered; archive is a flag with audited restore, never a delete.

## Known gaps carried forward
* Credit / debit notes in GST returns (GSTR-1 / 2B reconciliation) — phase 8.
* Transporter recovery (deducting a transit shortage from freight) — with freight, later.
* Broker spread reporting beyond the brokerage on each deal.

## Next: Phase 8 — GST, reports and AI-assisted alerts
GSTR-1 / GSTR-3B data, e-invoice (IRN / QR) and e-way bill integration points, TCS / TDS, management dashboards and
reports, and anomaly alerts (unusual weights, rates, losses) on top of the alert framework from phase 4.
