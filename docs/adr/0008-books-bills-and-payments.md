# ADR-0008: Append-only books; bills from slips; payments only to approved accounts

## Status
Accepted — Phase 5.

## Context
Money is where fraud in a mill usually happens: a bill booked for more than was received, a payment redirected to a
different account, a withdrawal hidden as a journal entry, last month's books quietly edited after they were filed.
The brief requires true records only, maker-checker on money and full auditability.

## Decision
1. **Vouchers and voucher lines are append-only** (DB triggers, as for the audit log and the stock ledger). `Books::post`
   is the only writer: debits equal credits to the paisa, each line on one side, the date in an open month. A
   correction is a reversal voucher, requested with `voucher.reverse` and approved by a different person who can post.
   `books:verify` runs nightly.
2. **Integer paise everywhere**, half-up rounding to the paisa; the amount owed on a purchase bill is rounded to the
   rupee with the difference on a "Round off" ledger (configurable).
3. **Purchase bills are computed, never typed.** A draft is created when a weighment slip is issued for an accepted lot
   against a purchase sauda, from the frozen slip (payable kg, quality rate cut) and the frozen sauda terms (rate,
   brokerage, credit days). Accounts reviews and posts it; it can be cancelled as a draft or reversed after posting.
4. **Payments pay only the party's approved bank account.** The account comes from the phase 2 maker-checker; the request
   freezes holder, IFSC and (encrypted) number, so a later change cannot redirect it. Release needs a different person
   with `payment.approve`; above a configurable amount a second, third person (excluding requester and first approver,
   by person). Release posts the payment voucher. Over-payment of a bill is impossible (amounts already requested are
   reserved). Cash to one person per day is capped at the Income-tax s. 40A(3) limit.
5. **Bank and cash never appear in journals**; money moves only through payments, receipts and contra entries.
6. **Bank files** (bulk-transfer CSV) are built from released payments, each payment in exactly one file; the file's
   SHA-256 is stored and re-checked on every download (which needs `bank.view` and is audited).
7. **Months are locked** by `period.lock`; reopening is a maker-checker request.
8. **Stock valuation** is a perpetual weighted average per product (append-only cost history): purchase cost spread over
   the physical net weight received; at batch close by-products take their realisable value and the rest goes to rice.
   Cost figures are shown only to accounts roles — hidden, never altered.

## Consequences
* The books can be re-checked from first principles at any time, and every rupee traces back to a slip or an approval.
* Paying a supplier needs three things to line up (posted bill, approved bank account, second person), which is slower
  than a free-form payment screen — deliberately.
* Advances and on-account payments are allowed but show up separately from bill allocations.
* No GST / TDS yet (phase 8); sales bills and receivables arrive with phase 6.
