# Cash book, bank book and attachments

## Cash book and bank book
Open them from **Accounts → Books & reports**: pick **Cash book** or **Bank book**, then choose a period (From – To).
They are also in **All reports**, under Accounts and finance, and both have a PDF button.

Each cash or bank account gets two tables:
* **Daily closing**: for each day, the opening balance, money received, money paid and the closing balance, with a TOTAL row.
* **Entries**: each entry in the period, line by line: the date, the voucher, who the money was received from or paid to (the other side of the voucher), the particulars, the amount and the running balance.

The figures come only from posted vouchers, so they always agree with the ledgers and the trial balance.
A transfer between cash and the bank (a contra voucher) appears in both books.

Rights:
* Cash book: `ledger.view`.
* Bank book: `bank.view` or `ledger.view`.

## Attachments
These record pages have an **Attach file** button and an **Attachments** section:

| Record | Documents it takes |
|---|---|
| Vehicle (gate entry) | lab result sheet, weighbridge slip, supplier invoice, other |
| Purchase bill | supplier invoice, weighbridge slip, other |
| Payment | signed payment voucher, bank transfer proof, other |
| Approval request | approval paper, other |

Rules:
* **File types:** a photo (JPEG or PNG) or a PDF, up to 5 MB.
* **Who can use them:** anyone who may view the record may attach files to it and open its files. Nobody else can, even with the link.
* **Tamper check:** each file is stored under its SHA-256 fingerprint. If the stored file no longer matches, it is not shown.
* **No edits:** attached files cannot be changed or removed.
* **Audit log:** every file attached and every file opened is recorded.

## Code
* `app/Domain/Reports/CashBankBooks.php`. It is wired into:
  * `ReportCatalog`;
  * the `AccountsReports` screen.
* `app/Domain/Files/Attachments.php`
* `app/Models/Attachment.php`
* `app/Http/Controllers/AttachmentController.php`, served at `/admin/attachments/{id}`.
* `app/Filament/Support/AttachmentsPanel.php`
* Migration `2026_11_09_000100_create_attachments` (append-only, tenant-secured).
* Tests:
  * `tests/Feature/Accounts/CashBankBooksTest.php`;
  * `tests/Feature/Files/AttachmentsTest.php`.
