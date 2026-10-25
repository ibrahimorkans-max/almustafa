# Print & branding: each mill prints as itself

**Administration → Print & branding** sets how the working mill looks on every slip, invoice, voucher and report.

**Who can change it:**
* the mill's **Super Admin** (right `entity.edit_own`), for its own mill;
* an **Owner** (`entity.manage`), for any of its company's mills. Choose the mill first.

## What a mill sets
| Section | Fields | Where it prints |
|---|---|---|
| Name and contacts | Name on documents, name in Urdu, tagline, phone, email, website, address, city, province, NTN, STRN | The letterhead on every document. Email and website appear on A4 and Letter. |
| Logo and colours | **Logo** (wide, PNG or JPG, up to 2 MB), **small emblem** (square, up to 1 MB; empty means the logo is used), main colour, second colour | The logo on A4, Letter and A6 letterheads; the emblem on compact headers and receipts. Colours on title bars, names, totals and lines. |
| Text on documents | Terms on sales invoices, a note on weighment and vehicle slips, a footer line | The terms replace the standard ones on sales invoices. The note goes above the signatures on slips. The footer line goes on every page. |
| Bank account on sales invoices | Account title, bank and branch, account number, IBAN | A "Pay into" box on sales invoices |

* **Preview a document** opens a sample PDF on the paper chosen for that computer (A4, A6, Letter or roll), so the result can be checked before printing real documents.
* **A new logo** replaces the old file, and "Remove the logo" takes it away. Pictures are checked to be real PNG or JPG images, not just files named that way. They are kept privately on the server (`storage/app/private/branding/<mill>/`), never in the public folder.
* **The change is recorded** in the audit log (`entity.branding.updated`).

## What a mill leaves empty
* **The first installation's mills (Al Mustafa):** the configured brand from `config/ricemill.php` still applies (logo, Urdu name, phone, colours), as before.
* **Any other customer's mill:** the printout uses that mill's own name and city. It shows **no logo** until one is uploaded, and neutral colours. It never shows another company's name or logo.

## Code
* Migration `2026_11_20_000100_mill_branding`: the new columns on `entities`.
* `PdfRenderer::brand()` builds the letterhead details from the mill, with the fallback rule above.
* `App\Filament\Pages\BrandingPage` (Print & branding), `BrandingPreviewController` (`/admin/branding/preview`) and `pdf/brand-sample.blade.php`.
* Templates:
  * `pdf/letterhead` (logo optional, tagline, email and website);
  * `pdf/footer` (footer line);
  * `documents/partials/header-compact` and `documents/thermal` (emblem optional);
  * `sales/invoice` (terms and bank);
  * `slips/weighment` and `gate/partials/slip-body` (slip note).
* `DocumentRenderer` puts the logo inline for documents shown in the browser.
* Tests: `tests/Feature/Documents/BrandingTest.php`.
