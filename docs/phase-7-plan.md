# Phase 7 — Plan: Claims, pricing and archive

## 1. Claims (weight / quality disputes)
| Table | Purpose |
|---|---|
| `claims` | Against a **sales invoice** (buyer says short weight / poor quality / damage) or a **purchase bill** (mill claims against the supplier, e.g. hidden moisture found later). Type, description, amount claimed, evidence (photo / document hashes from the content-addressed file store), status `open → approved → settled` / `rejected` / `withdrawn` |
| `claim_events` | **Append-only** history: raised, note, evidence added, approved (with the settled amount, which may be less than claimed), rejected, settled |

* Raised and worked by `claim.manage`; **approval by a different person** with `claim.approve` (maker-checker by
  person). The approver fixes the settlement amount; it cannot exceed the claim or what is still owed on the document.
* **Settlement posts a note into the books**:
  * sales claim → **credit note** to the buyer: Dr *Sales claims & allowances* (taxable part), Dr output GST in the
    same CGST / SGST / IGST proportion as the invoice, Cr buyer — so GST is reversed correctly;
  * purchase claim → **debit note** to the supplier: Dr supplier, Cr *Purchase claims recovered*.
  Notes are numbered per financial year, append-only, and reduce the document's outstanding amount (receivables /
  payables ageing and credit exposure follow).
* Claims can be linked to the transporter for transit shortages (information only in this phase).

## 2. Pricing (rate cards, cost and margin)
| Table | Purpose |
|---|---|
| `price_lists` / `price_list_items` | Selling rate per product (₹ per quintal), valid from / to; one active list per date |
| `markup_settings` | Default markup % per product category used to *suggest* a selling rate from current cost |

* **Rate changes go through maker-checker**: `pricing.rate.edit` requests, a different person with
  `approval.decide` approves; the old rate stays in history (items are versioned, never edited in place).
* **Cost, margin and broker spread are confidential, not falsified**: they are shown only to holders of
  `pricing.margin.view` — on sales saudas, invoices and price lists (the stock value report is a book figure and stays with
  `ledger.view`). For everyone else the
  fields are **absent** (not zero, not adjusted); every other figure is identical for all roles. Tests compare what two
  roles see to prove no number differs.
* Margin = selling rate − weighted-average cost (phase 5 valuation) per quintal; markup suggestions need
  `pricing.markup.set` to change.
* Sales saudas show the list rate and the difference; a rate below the list is flagged for the approver.

## 3. Archive
* Closed records older than a retention period (default 24 months, configurable) are **archived, never deleted**:
  saudas (completed / cancelled / expired), gate entries (exited / cancelled), production batches (closed), requisitions
  (issued / rejected / cancelled). Archiving sets `archived_at`; working screens hide archived rows by default.
* **Archive search** (`archive.search`): one screen across these types by number, party, vehicle and date.
* **Restore** (`archive.restore`) needs a reason and is audited; the books, stock ledger and audit log are never
  archived (they are the permanent record).
* Monthly `archive:run` command (scheduled), with a dry-run option that reports what would be archived.

## Tests (planned)
Claim lifecycle and maker-checker; settlement amount limits; credit note GST split and voucher lines; debit note;
outstanding / ageing / credit after a note; append-only claim history; price list versioning and rate change via
approval; margin fields absent (not altered) for roles without permission, identical figures otherwise; markup
suggestion; archive selection rules, dry run, restore with reason and audit; screens per role; both databases.

## Assumptions (change if wrong)
* Claims settle by a note against the document; cash refunds go through the normal payment / receipt flow afterwards.
* Credit notes reverse GST in the same proportion as the original invoice (financial credit notes without GST are
  out of scope).
* Retention before archiving: 24 months after closing.
