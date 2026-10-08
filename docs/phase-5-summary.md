# Phase 5 — Summary: Accounts

## Built

| Area | What exists |
|---|---|
| Chart of accounts | Tally-style groups (Capital, Current Liabilities → Sundry Creditors, Current Assets → Sundry Debtors / Bank / Cash / Stock-in-hand, Purchase, Direct / Indirect Expenses, Sales, …) and system ledgers installed for every entity (on provisioning; `accounts:install` for existing ones). Party ledgers are created on first use; mill bank accounts keep the number encrypted and show it masked |
| Books | `vouchers` + `voucher_lines` append-only (DB triggers), integer paise, gap-free numbers per type and financial year (`KNL/PV/2026-27/0001`). Debits = credits, one side per line, open period, active ledgers — or nothing is posted. Corrections by reversal voucher (maker-checker). `books:verify` nightly at 02:45 IST |
| Purchase bills | Drafted automatically when a slip is issued for an accepted lot against a purchase sauda: gross = payable kg × rate, quality rate cut, brokerage to the broker, round-off to the rupee, due date from the sauda's credit days. Accounts posts (purchase voucher) or cancels the draft; posted bills are reversed only through an approved request |
| Payments | Request (bills settled oldest first, remainder on account) → release by a different person with `payment.approve`; above ₹5 lakh (configurable) a second approval by a third person. Payee frozen from the party's approved bank account. Over-payment refused; cash capped at the s. 40A(3) limit per person per day. Release posts the payment voucher; UTR / cheque number recorded afterwards |
| Bank files | Bulk-transfer CSV per mill bank account from released NEFT / RTGS / UPI payments, each payment once; SHA-256 stored and re-checked on download (`bank.view`, audited); formula injection neutralised |
| Manual entries | Receipts, contra (cash ↔ bank) and journals by `ledger.post`; bank and cash are not allowed in journals |
| Period lock | Finished months locked by `period.lock`; posting into them refused everywhere; reopening via maker-checker |
| Reconciliation | Statement CSV import (DD/MM/YYYY or ISO dates, Indian digit grouping), duplicates skipped on re-import, auto-match by amount and side within ±5 days with UTR preferred, manual match / unmatch |
| Reports | Trial balance, ledger statement (screen + PDF, download audited), day book, payables ageing (not due / 0–30 / 31–60 / 61–90 / 90+, separating paid from awaiting approval), stock value |
| Valuation | Weighted-average cost per product as an append-only history: purchase cost (gross − cut + brokerage) over the physical net kg received; at batch close by-products at their realisable value (product setting, accounts only), the rest to rice by weight |
| Admin | New nav group **Accounts**: purchase bills (badge with drafts), payments, vouchers (new receipt / contra / journal, request reversal), ledgers (with balances and statement link), bank files, bank reconciliation, books & reports, period locks. Product form gains the by-product valuation rate for accounts users |
| Demo data | A mill bank account per demo mill and realisable values for husk, bran and broken |

## Tested
* **PostgreSQL:** 349 passed, 1 skipped. **MariaDB:** 340 passed, 10 skipped (PostgreSQL-only RLS tests). Larastan level 6 and Pint clean.
* New: books (11 — chart install, numbering, balance rules, immutability, single reversal, tamper detection, journals
  without bank / cash, period lock + maker-checker reopen, entity isolation), purchase bills (6 — calculator rounding,
  draft from slip, posted voucher lines, permissions, reversal through approval, no bill for lots without a sauda),
  payments (7 — release, frozen payee, refusals, two approvers by person, cash limit, rejection frees the bill, bank file
  + audit + tamper detection), valuation / reconciliation / reports (4), screens (13), demo seed (1).

## Fixed along the way
* Ledger statements could list two vouchers posted in the same second out of order (timestamps keep whole seconds);
  lines are now ordered by the time-ordered voucher id.
* A statement date such as 31/02 made Carbon throw instead of being reported as unreadable.
* A failed approval execution now also notifies the subject (payment / stock count), so nothing stays "pending" forever.
* Self-referencing account-group foreign key is added after the table exists (PostgreSQL).

## Decisions (ADR-0008)
Append-only books with reversals; bills computed from frozen slip + sauda terms; payments only to approved, frozen payees
with different-person (and above the limit, two-person) release; no bank / cash in journals; bank-file hash; period locks
with maker-checker reopening; weighted-average valuation through milling.

## Known gaps carried forward
* GST, TDS / TCS (s. 194Q), e-invoice and e-way bill (phase 8).
* Sales bills, receivables ageing and customer credit control (phase 6).
* Bank-specific bulk-upload layouts and direct bank APIs; penny-drop verification of party accounts.
* Mandi fee / cess lines on purchase bills (configurable, off) — not built yet.
* Opening balances import for an existing mill.

## Next: Phase 6 — Sales, dispatch and ANPR
Sales saudas and orders, loading and dispatch through the gate (outbound weighment), gate passes, sales bills into the
books, ANPR / boom barrier / display drivers in the agent.
