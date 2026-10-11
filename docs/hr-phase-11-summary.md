# HR module — Phase 11 summary: income, expenses, profit and loss

Plan: [hr-phases-5-12-plan.md](hr-phases-5-12-plan.md). Previous: [hr-phase-10-summary.md](hr-phase-10-summary.md).

## Built
| Area | What exists |
|---|---|
| Expenses and income | **Accounts → Expenses & income** (`Expenses`, needs `ledger.post`).<br>• An entry has a category, date, amount, description and an optional receipt (photo or PDF). It is paid from or received into cash or bank, or is on credit with a party<br>• Posting makes the voucher: an expense is *Dr category ledger / Cr cash-bank or party*; income is the reverse<br>• An entry can be kept as a **draft** (nothing in the books), then **Post** (the amount and payment can be corrected) or **Cancel** with a reason<br>• A posted entry is corrected by reversing its voucher, through the existing approval<br>• The menu badge counts drafts |
| Recurring expenses | **Accounts → Recurring expenses**: rent, electricity, guards … with a usual amount, day of the month (1–28), first and last month.<br>• Daily at 06:30 PKT (`expenses:recurring`) each template due drafts its month's entry; a month missed while the server was down is caught up; a month is never drafted twice<br>• **Draft due recurring now** on the expenses list does the same at once |
| Budgets | **Budget** on **Accounts → Expense categories**: an amount per category and month. |
| Reports | **Accounts → Profit & loss**, from posted vouchers only, each as a screen, a PDF on the letterhead and an Excel file (downloads are audited):<br>• **Profit and loss**: sales − cost of goods (opening stock + purchases + direct expenses − closing stock) = gross profit; − operating expenses (payroll included, shown in a note) + other income = net profit. Stock is valued from the stock ledger at the weighted-average cost in force on each date<br>• **Month by month**<br>• **Income and expenses against budget** (actual from each category's ledger; a ledger shared by two categories is marked)<br>• **Payroll cost by department** from finalized payrolls (gross, employer EOBI / PESSI, headcount)<br>• **Cash flow**: cash and bank at the start, money in and out by purpose, at the end (transfers between cash and bank left out)<br>• **Salaries, claims and advances outstanding**<br>• **Milling cost per maund** (direct + operating expenses, purchases left out, over the maunds of paddy issued to milling) in the P&L notes<br>• Every ledger line opens its ledger statement for the same period (drill-down); **This tax year** sets the period from 1 July |
| Dashboards | • **People** (anyone with `hr.employee.view`): present today of headcount, late, on leave, absent; punches to review; leave to decide and in the next 7 days; with `hr.salary.view` the payroll month and status and advances to recover; with `hr.documents.generate` payslips of the last payroll never shared, opened or downloaded<br>• **This month from the books** (`dashboard.view` + `ledger.view`): sales, net profit so far, payroll cost, milling cost per maund |
| Alerts | With the nightly anomaly scan (03:00 PKT), raised once each and stating the numbers:<br>• **Abnormal overtime**: above 60 hours in the month (`RICEMILL_OVERTIME_ALERT_HOURS`), or more than twice the employee's usual<br>• **Payroll variance**: gross pay more than 15 % (`RICEMILL_PAYROLL_VARIANCE_BP`) from the month before<br>• **Minimum wage shortfall** in a finalized payroll<br>• **Failed delivery**: a bulk document run that failed or left records unproduced |

**Notes:**
* Purchases are expensed when billed, so stock received without a purchase bill (opening stock entered on the stock ledger) shows as a gain in the month it arrives. So start profit and loss periods on or after the go-live day.
* The reports read one mill at a time. A Super Admin in "All firms" mode should switch to a mill first.

## Tested
`tests/Feature/Accounts/ExpensesAndReportsTest.php`, 14 tests:
* **Expenses:** draft, post with a corrected amount, cancel; income and on-credit postings; refusals (cash and party together, future dates, non-cash ledgers, bad receipts, no permission).
* **Recurring:** drafts on the day, catch-up, never twice, ended templates skipped.
* **Budgets.**
* **Reports:** a month of books checked by hand (opening and closing stock on their dates, cost of goods, gross and net profit, payroll cost, month by month, milling cost per maund); cash flow by purpose; budget against actual; payroll cost by department (unfinalized runs left out); staff balances; every report as a table.
* **Alerts:** each of the four rules, and nothing raised twice.
* **Screens:** who opens which screen; expense entry, recurring template create and edit, posting a draft from the list, cancelling, budget; P&L on screen with ledger links, PDF and Excel; dashboard widgets per role.

Results:
- PostgreSQL and MariaDB both pass.
- Pint is clean.
- Larastan reports no errors.
