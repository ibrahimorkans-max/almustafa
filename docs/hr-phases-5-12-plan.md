# HR module — phases 5 to 12 plan (payroll, documents, billing, profit and loss)

Branch `hr-payroll` (from `pakistan-localisation`). Every rule follows Pakistani law and practice. Rates, slabs and thresholds are **effective-dated settings** that the owner or tax adviser confirms; the system never hard-codes a figure that changes by Finance Act or notification.

## Phase 5 — wages engine
* **Monthly salaried:**
  * Each pay head is prorated by paid days over the **calendar days** of the month (setting: calendar or 26).
  * Holidays and weekly offs are paid.
  * Loss of pay = absent days + unpaid leave + days with a missing punch.
  * A salary change mid-month is applied day by day, using the structure in force on each day.
* **Daily wage:** day rate × days present (half days count 0.5) plus paid leave. Holidays are paid only when the setting says so. Weekly offs are unpaid.
* **Overtime** (Factories Act s. 47):
  * Paid at **2×** the ordinary hourly rate.
  * The hourly rate is Basic ÷ 208 hours for monthly staff (setting), or the day rate ÷ shift hours.
  * Minutes are rounded down to 30-minute blocks (setting).
* **Work on a holiday or weekly off:** the day is paid at 2× in total (setting). Monthly staff get the extra 1×, daily staff the full 2×.
* **Piece rate:**
  * Rate per unit from the salary structure (bag, maund, ton).
  * Quantities come from **piece-work entries**, typed in or taken from a gate entry (bags loaded or unloaded) or a production batch (maund milled), and split equally across a gang.
  * Entries are append-only; the same source cannot be counted twice for an employee.
* **Advances and loans:**
  * Requested by HR and approved by a different person with `hr.payroll.approve`.
  * Disbursed from cash or bank, posting *Dr Staff advances / Cr cash-bank*.
  * Instalments are recovered in payroll, or repaid in cash.
  * Balances and the recovery schedule are kept in an append-only recoveries table.

## Phase 6 — allowances, bonuses, statutory deductions
* **Allowance rules:** fixed, percentage of Basic, per day present, or conditional on attendance (e.g. attendance allowance only with no absences). Each rule can be limited to departments or employment types.
* **Bonus:**
  * Festival bonus (Eid ul Fitr / ul Adha): a rule such as one Basic, prorated by service months.
  * Performance and attendance bonuses.
  * Ad-hoc awards need a second person's approval.
* **Fines and other deductions:** each needs a reason and approval. Fines are capped per Payment of Wages Act (setting: 3 % of wages).
* **Statutory settings** (effective-dated, verify before use):
  * **EOBI:** employee 1 % and employer 5 % of the minimum wage.
  * **PESSI** (Punjab social security): employer 6 % of wages up to the ceiling.
  * **Income tax on salary (s. 149):** annual slabs, deducted monthly on projected annual pay less tax already deducted this tax year.
  * **Punjab minimum wage:** the run warns about anyone paid below it.
* **Exports:**
  * EOBI contribution CSV, PESSI contribution CSV, s. 149 deduction statement;
  * wages register, overtime register (the attendance register already exists).

## Phase 7 — payroll run
* **Run per entity and month:** draft → attendance locked → calculated → approved (a different person with `hr.payroll.approve`) → finalized → paid.
* **Attendance lock:** the month's days are snapshotted on every item. Later reviews, manual punches and leave decisions for that month are refused.
* **Recalculating** before approval writes a new calculation; earlier ones stay as history.
* **Finalize** posts the payroll voucher:
  * *Dr* salaries, overtime, bonus and the employer contributions (EOBI, PESSI) as expenses.
  * *Cr* salaries payable, income tax withheld (s. 149), EOBI payable, PESSI payable and staff advances (recoveries).
  * It also numbers the payslips (`AMR/PSL/2026-27/0001`) and locks the month.
* **Pay:**
  * Bank file (IBAN bulk CSV: a generic layout plus bank layouts).
  * Cash register with the employee's acknowledgement (signature or thumb image).
  * Status per employee; each payment posts *Dr salaries payable / Cr bank-cash*.

## Phase 8 — documents engine
* **Document model:** every document type builds one neutral model (header fields, line tables, totals, amount in words, notes). Four layouts (Classic, Compact, Detailed, Minimal) render any type; a thermal layout serves 80 / 58 mm paper.
* **Tables:**
  * `document_templates` and `document_template_versions`, append-only: logo, header and footer text, colours, signature blocks, terms, default toggles;
  * `generated_documents` (number, source, template version, language, paper, options, file, SHA-256, status, reprint count);
  * `document_shares`, and `document_access_log` (append-only).
* **Types:**
  * payslip, wage slip, advance voucher and recovery schedule, bonus slip;
  * reimbursement voucher, contractor bill, cash payment receipt;
  * final settlement, attendance summary, payroll register, salary certificate and employment letter.
* **Paper:** A4, A5, half-A4 (two slips per page), 80 mm and 58 mm thermal (PDF, plus ESC/POS text for receipt printers).
* **Languages:** English, Urdu (right to left, Lateef font) and bilingual. Western or Urdu digits. Amount in words in English or Urdu.
* **Toggles** change what is shown, never the figures: employer contributions, YTD, leave balance, attendance calendar, loan balance, QR, stamp, signatures. CNIC and IBAN stay masked unless the user has `hr.employee.sensitive.view`.
* **Output:**
  * PDF; printable HTML;
  * PNG via `pdftoppm` (installed by `provision.sh`);
  * Excel (XLSX) and CSV for registers;
  * password-protected PDF (the employee's CNIC last 5 digits, or a PIN).
* **Lifecycle:**
  * Numbers are gap-free per series and tax year.
  * Drafts are watermarked DRAFT and cannot be shared.
  * Reprints are watermarked DUPLICATE and counted.
  * Corrections are new versions linked to the original.
  * A QR code opens `/verify` with the number, date and status only.

## Phase 9 — delivery
* **Channels:**
  * download and print;
  * email with the PDF attached;
  * WhatsApp and SMS as signed, expiring, revocable links (wa.me share link until a provider is configured).
* **Bulk generation** (all, department or selection): a queued job with progress, retries and a failure list.
* **Self-service:** employees see their own documents in the panel and through the API.
* **Automation:** payslips are generated automatically after finalization (setting). Reminders go out for unsigned cash acknowledgements.
* **Audit:** every view, download, print and share is logged.

## Phase 10 — reimbursements and labour contractors
* **Reimbursements:** claim with receipt photos → supervisor approves → accounts approves → paid. Posts *Dr expense category / Cr employee claims payable*, and the payment posts *Dr payable / Cr cash-bank*. A voucher is printed.
* **Contractor bills:**
  * The contractor is a vendor party. Workers are employees linked to that contractor.
  * Quantities are taken from their attendance (man-days) or piece-work entries, × rate.
  * Optional provincial sales tax on services and withholding u/s 153(1)(b).
  * Approval, then post *Dr labour expense / Cr contractor / Cr WHT*. Payment goes through the existing party payments.

## Phase 11 — income, expenses, profit and loss
* **Expense and income categories** mapped to ledgers, with monthly budgets.
* **Recurring expenses** (rent, electricity, salaries of guards…) create draft vouchers each month for accounts to post.
* **Expenses** are posted with receipts attached.
* **Reports** from ledger data only:
  * P&L: sales − cost of goods (opening stock + purchases + direct expenses − closing stock at valuation) = gross profit; − operating expenses including payroll = net profit.
  * Income, expense, department and mill cost, payroll cost summary, month-over-month comparison.
  * Cash flow summary, outstanding salaries and advances.
  * Drill-down to vouchers; PDF and Excel export.
* **Dashboards:**
  * HR: headcount, present / absent / late today, flagged punches, upcoming leave, payroll status, advances, undelivered documents.
  * Owner: payroll cost, P&L, cost per maund milled.
* **Alerts:** abnormal overtime, payroll variance versus last month, minimum-wage shortfall, failed deliveries (repeated mock locations and document expiry already exist).

## Phase 12 — hardening
* Privacy review of every document and API.
* Authorization matrix tests for all new screens and endpoints.
* Performance: indexes, eager loading, bulk generation limits.
* OpenAPI for the document endpoints.
* Summary docs.
