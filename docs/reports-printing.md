# Printing reports: a PDF on every report, and all reports in one click

## PDF on every report screen
| Screen | Buttons |
|---|---|
| Administration → Reports (purchase, sales and gate registers, stock movement, production yield) | **PDF**, CSV, All reports |
| Accounts → Books & reports (trial balance, day book, payables and receivables ageing, stock value) | **PDF** (ledger statement keeps its own PDF), All reports |
| Accounts → Profit & loss (P&L, month by month, budget, payroll cost, cash flow, staff balances) | PDF, Excel, All reports |
| Accounts → Tax registers | **Annex-C PDF**, **Withholding PDF**, CSVs, All reports |
| HR → Attendance register | **PDF** (for the chosen department too), CSV, All reports |
| HR → Statutory reports (wages, overtime, EOBI, PESSI, s. 149) | **PDF**, Excel, CSV, All reports |
| Administration → Complete business report | PDF, Excel, All reports |
| Gate → Vehicle register | Print slip, print all slips, day register, Excel |

A PDF opens in a new browser tab, ready to print or save. It is printed on the mill's letterhead in A4 landscape, and
long tables repeat their header on every page.

## All reports in one click
**Administration → All reports (print in one click)**
1. Choose the period: today, yesterday, this week, this month, last month, this financial year (July to June), or your
   own dates.
2. The reports appear as cards, one per category:
   * **Summary**
   * **Accounts and finance**
   * **Purchases and sales**
   * **Gate and vehicles**
   * **Stock and production**
   * **Tax**
   * **HR and payroll**

   Every report is ticked at first. Tick or clear a whole category with the box in its heading. **Tick all** and
   **Clear** work across the whole page.
3. **Print all selected reports — PDF** gives one file:
   * the period and who printed it;
   * a numbered contents page by category;
   * each report on a new page under a category band;
   * signature boxes at the end: prepared by, checked by (a second person) and approved by.
4. **Excel (all selected)** gives one workbook with a sheet per report.
5. Each card also has **Print category**, and every report has its own **PDF** and **Excel** links.

How the period is used:
* **Range reports** cover the whole period: P&L, cash flow, registers, vehicle register, stock movement.
* **As-on reports** use the last day: trial balance, ageing, day book, staff balances.
* **Monthly reports** print each month of the period, up to 12: tax registers, payroll statutory reports, attendance,
  budget.
* **Stock value** is as it is now.

A report that cannot be made yet still gets its page, with the reason. For example, payroll that is not finalized
gives "the payroll is not finalized yet".

Each person only sees, and can only print, the reports their role allows. Accounts reports need `ledger.view`, tax
needs `report.tax`, and HR needs the HR or payroll rights. Every print and download is written to the audit log.

## Code
* `app/Domain/Reports/ReportCatalog.php`: the list of reports, categories, periods and rights, turned into printable
  tables, with the combined PDF and Excel.
* `app/Http/Controllers/ReportPrintController.php` serves `GET /admin/reports/print?r[]=…&from=…&to=…[&format=xlsx]`.
* `resources/views/reports/book.blade.php` is the PDF layout.
* `app/Filament/Pages/AllReportsPage.php` and `resources/views/filament/pages/all-reports.blade.php` are the page.
* `tests/Feature/Reports/AllReportsTest.php`.
