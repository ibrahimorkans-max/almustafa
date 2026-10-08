# ADR-0010: Claims settle by notes; confidential figures are hidden, never altered; archive never deletes

## Status
Accepted — Phase 7.

## Context
The brief forbids any feature that shows altered, dummy or percentage-adjusted figures to some users, or a second set of
books; cost, margin and broker spread may only be *hidden* by permission. Claims are a classic place for quiet
write-offs, and old records must stay retrievable for disputes and audits.

## Decision
1. **Claims are records with an append-only history** (`claim_events`, DB triggers). They are raised against a posted
   sales invoice or purchase bill and can never exceed what is still claimable on that document. A different *person*
   with `claim.approve` fixes the settlement (≤ claimed). Accounts posts it as a **credit note** (sales: taxable part to
   *Sales claims & allowances*, GST reversed in the invoice's own CGST / SGST / IGST proportion, rounding absorbed by
   the taxable part) or a **debit note** (purchase: supplier Dr, *Purchase claims recovered* Cr). Notes reduce the
   document's outstanding amount; ageing and credit exposure follow. Reversing a note returns the claim to "approved";
   a document with approved / settled claims cannot be reversed until they are dealt with. Evidence is stored as
   content hashes in the existing file store.
2. **Rates are versioned and approved.** Price-list items are append-only; a change is requested with
   `pricing.rate.edit` and applied only after a different person approves. Sales saudas show the list rate and flag a
   deal below it for the approver.
3. **Confidential = absent.** Cost, margin, markup and the suggested rate are computed only for holders of
   `pricing.margin.view` (the service returns `null` otherwise, and the screens do not render the section at all).
   Every other figure is the same for every role; a test renders the same invoice for two roles and compares them.
   Stock valuation (a book figure) stays visible to accounts (`ledger.view`); the by-product valuation rate is set by
   `ledger.post`.
4. **Archive never deletes.** Closed working records (saudas, gate entries, batches, requisitions, claims) are marked
   `archived_at` 24 months (configurable) after closing by a monthly job with a dry-run mode; working lists hide them
   by default (filter to show). Archive search needs `archive.search`; restore needs `archive.restore` and a reason and
   is audited. Books, stock ledger, audit log and other append-only records are never archived.

## Consequences
* A claim cannot be "made to disappear": it is either settled with a note anyone can trace, rejected, or withdrawn,
  with who did what in its history.
* Roles without margin permission see fewer fields, never different numbers.
* Archived records remain referenced by vouchers and stock entries; relations keep working because archiving is a flag,
  not a scope.
