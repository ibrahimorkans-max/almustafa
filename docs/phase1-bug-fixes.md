# Phase 1: the seven reported bugs

Each fix is at its root cause. No data is changed, and no migration is needed.

| # | Bug | Root cause | Fix |
|---|---|---|---|
| 1 | Product dropdowns | Each form built its own list: no search, inactive products offered (then refused or blank once saved), no product code, and an empty list with no explanation in a new mill (new mills start with no products). | One picker, `App\Filament\Support\ProductSelect`, used by Saudas (purchase and sales), Quotes, Paddy requirements, Deduction rules, Stock counts and Price lists. It searches by code or name, shows "CODE — Name", lists only **active** products of the right kind, keeps the label of a product made inactive later, and with nothing to choose says "This mill has no active … products yet" with a link to Products. Requisitions also list active products only. |
| 2 | Sales Sauda visibility | Sales deals checked the purchase desk's rights (`deal.view`, `deal.view_own`), while making one needs `sales.order`. Sales staff could make a sales deal but not see it. | Direction-aware rights. **Sales saudas:** seeing needs `sales.view` (or a deal right); making and sending for approval need `sales.order`. **Purchase saudas:** unchanged. `Sauda::scopeVisibleTo()` takes the direction. |
| 3 | Stock Count server error | When the person counting may approve their own adjustment (Owner or Super Admin), the approval was applied inside `submit()`, which saved the count. The service then saved its older copy, and the version lock refused it ("changed by someone else"), giving a 500 error. | The count is reloaded before the approval is linked (`StockCountService::record`). |
| 4 | Claims document content not visible | The claim page showed evidence only as SHA-256 fingerprints, with no way to open the files. The bill claimed against showed only its number. | **Photos & documents:** each file is a link that opens it (`/admin/claims/{id}/evidence/{sha256}`). Only files recorded on that claim are served, and only to someone who may view the claim. Each file is checked against its fingerprint first. **Document claimed against:** number (linked to the bill), date, product and amount. The form's Document list shows party, date and amount, says when there are no posted bills, and clears when the side is switched. |
| 5 | Sidebar closing by itself | Filament closes the menu on every item click when the window is 1024 px wide or less, and remembers it closed. A laptop at 1280 px with Windows 125 % scaling is exactly 1024 px, so the docked menu folded after each click. | `public/js/sidebar-keep-open.js`: on a computer (1024 px and wider) the menu stays open after a click, and a menu folded only by that bug is opened again. A menu the person folds with the button stays folded. Phones keep the normal behaviour (the menu covers the page and closes after a click). |
| 6 | No Bank option in a new expense | The form offered "Cash or bank" with one mixed list, so "Bank" never appeared as a choice. A mill without a bank account got no hint. | **Paid from: Cash / Bank / On credit**, the account list filtered to the choice, and "No bank account yet: add one in the chart of accounts" when there is none. The same applies to posting a draft and to recurring expenses. Stored data is unchanged (`cash_ledger_id`). |
| 7 | Contra Cash → Bank and Bank → Cash | A single "Contra (cash ↔ bank)" entry with the same mixed list on both sides. | Vouchers → New entry has four entries: **Cash → Bank (deposit)**, **Bank → Cash (withdrawal)**, **Bank → Bank transfer**, **Cash → Cash**. Each side lists only the kind it names, the two sides must differ, and a single matching account is pre-selected. Posting is unchanged (`ManualVouchers::contra`). |

## Changed behaviour to note
* **Inventory Managers** (who have `sales.view`) now see Sales saudas. They still cannot make them without `sales.order`.

## Tests
* `tests/Feature/Fixes/Phase1BugFixesTest.php` covers bugs 1, 2, 4, 6 and 7.
* `tests/Feature/Inventory/RequisitionAndCountTest.php` covers the count booked at once (bug 3).
* `tests/Feature/Sales/Phase6AdminTest.php`: the Inventory Manager now opens Sales saudas.
* Checked in the browser:
  * at 1024 px the menu stays open after a click, and at phone width it closes;
  * the expense form shows Cash / Bank / On credit, and Bank lists only the bank account;
  * Vouchers shows the four contra entries.
