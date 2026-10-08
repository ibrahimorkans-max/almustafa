# Phase 5 — Plan: Accounts

Goal: a double-entry book that every rupee can be traced through — from the weighment slip to the purchase bill, to the
approved payment and the bank — with the same rules as stock: append-only, reversals instead of edits, maker-checker on
money leaving the mill, nothing ever shown with altered figures.

## 1. Chart of accounts
| Table | Purpose |
|---|---|
| `account_groups` | Tally-style tree familiar to Indian accountants: Capital, Loans, Current Liabilities (Sundry Creditors, Duties & Taxes, Provisions), Fixed Assets, Current Assets (Sundry Debtors, Bank Accounts, Cash-in-hand, Stock-in-hand, Loans & Advances), Purchase / Sales Accounts, Direct / Indirect Expenses and Incomes. Nature (asset / liability / income / expense) decides the sign and the P&L or balance-sheet side |
| `ledger_accounts` | Postable accounts. System accounts are seeded per entity (Purchase – Paddy, Brokerage, Rate Cut / Quality Claims, Cash, Round-off, Stock-in-hand, …); each party gets its own ledger on first use (creditor or debtor by role); each mill bank account is a ledger with masked number |

## 2. Vouchers (the books)
| Table | Purpose |
|---|---|
| `vouchers` | **Append-only** header: type (purchase, payment, receipt, journal, contra, debit note, credit note; sales in phase 6), number per type and financial year (`KNL/PV/2026-27/0001`), date, narration, source (gate entry, payment, …), `reverses_id` |
| `voucher_lines` | **Append-only** Dr / Cr lines in integer paise against ledger accounts; optional party bill reference (for outstanding / ageing) |

Rules enforced in one place (`Books::post`): Dr = Cr to the paisa, at least two lines, positive amounts, date not in a
locked period, accounts active and of this entity. DB triggers reject UPDATE / DELETE on both tables. A correction is a
reversal voucher (`voucher.reverse` request → maker-checker), then a fresh voucher. A nightly `books:verify` checks that
every voucher balances and that balances equal the sum of lines.

## 3. Purchase bills from the gate
When a slip is issued for an accepted lot against a purchase sauda, a **purchase voucher** is drafted from the frozen slip
snapshot and the frozen sauda terms:
* Dr Purchase – Paddy: payable kg × sauda rate (per quintal, rounded to the paisa, half-up)
* Cr Party (creditor): the same less the quality rate cut; Cr Rate Cut / Quality Claims for the cut
* Brokerage (if the sauda has a broker and brokerage rate): Dr Brokerage, Cr Broker
* Round-off to the rupee on the party line (configurable)

Drafts are reviewed by accounts (`ledger.post`) and posted, so a wrong slip can still be stopped before it reaches the
books; posting is idempotent per gate entry. Due date = slip date + sauda payment days.

## 4. Payments and receipts
* **Payment request** (`payment.create`): party, bills being paid (or on-account), amount, mode (NEFT / RTGS / cheque /
  cash / UPI), from which mill bank or cash ledger. The payee's bank details come only from the **approved** party bank
  account (phase 2 maker-checker); the request freezes which one.
* **Approval** (`payment.approve`, a different *person*; above a configurable amount a second approver) → posts the
  payment voucher and, for bank modes, adds the payment to an **NEFT/RTGS bank file** (CSV in the common bulk-upload
  layout) for the accountant to upload to the bank. No bank API in this phase.
* Over-payment against a bill is refused; partial payments and advances are allowed and tracked per bill.
* Cash payments above the Income-tax limit (₹10,000 per person per day, s. 40A(3)) are refused with a clear message.
* Receipts (`ledger.post`) for customer money, on account until sales bills exist in phase 6.
* Devices: payments prepared offline stay `pending_online` until confirmed online (already in the sync protocol).

## 5. Books and reports
Day book, cash book, bank book, party ledger statement (with running balance, printable PDF), trial balance, payables
outstanding with ageing (0–30 / 31–60 / 61–90 / 90+), due-today list for the accountant.

## 6. Period lock
Months can be locked (`period.lock`); posting into a locked month is refused everywhere. Unlocking needs a reason and a
second person, and is audited.

## 7. Stock valuation
Paddy cost per kg from posted purchase vouchers (weighted average per product), carried through production: by-products
are valued at a configurable rate per kg (net realisable value) and the rest of the batch cost goes to the rice. Shown
as the stock-in-hand value per godown for `ledger.view` holders. **Cost figures are hidden** from roles without
permission, never altered.

## 8. Bank reconciliation (light)
Import a bank statement CSV, auto-match by amount + reference / UTR, manual match for the rest, list unreconciled items.

## Out of scope here
GST returns and e-invoice / e-way bill (phase 8), TDS / TCS (phase 8, with GST), sales bills and customer credit
control (phase 6), payroll.

## Assumptions (change if wrong)
* Books follow the Indian financial year (April–March); amounts in integer paise; rounding half-up to the paisa.
* Purchase bills are posted after review, not automatically.
* Bank file = generic CSV first; a bank-specific layout can be added when the mill's bank is known.
* Mandi fee / market fee and the HRDF / cess that some states levy on paddy purchases are configurable expense lines on
  the purchase bill, default off.

## Tests (planned)
Unbalanced / zero / locked-period / cross-entity postings refused; vouchers immutable at DB level; reversal chain;
purchase bill amounts from slip snapshots (deductions, rate cut, brokerage, rounding); idempotent bill per gate entry;
payment needs approved bank account, different-person approval, no over-payment, cash limit; bank file content; trial
balance balances; ageing buckets; valuation through a batch; screens per role; both databases.
