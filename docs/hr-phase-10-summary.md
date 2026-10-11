# HR module — Phase 10 summary: staff claims and labour contractors

Plan: [hr-phases-5-12-plan.md](hr-phases-5-12-plan.md). Previous: [hr-phase-9-summary.md](hr-phase-9-summary.md).

## Built
| Area | What exists |
|---|---|
| Staff claims | **HR → Staff claims** (`Reimbursements`).<br>• HR enters a claim for an employee: what it was for, then 1–30 lines (category, date within the last 90 days, description, amount, receipt photo or PDF)<br>• A receipt is required above Rs 500 a line<br>• **Check** (supervisor: `hr.attendance.review` or `hr.leave.approve`) → **Approve** (accounts: `ledger.post`) posts *Dr each line's expense ledger / Cr Staff expense claims payable* → **Pay** (`payment.create`) from cash or bank posts *Dr payable / Cr cash-bank*<br>• Nobody decides on a claim they made or submitted; the checker cannot also approve<br>• **Refuse** with a reason before approval; a refused claim is not edited, a corrected one is submitted<br>• Receipts download from the claim's page; the **Voucher** prints once approved<br>• The menu badge counts the claims waiting for the signed-in person's step |
| Contractor bills | **HR → Contractor bills** (`ContractorBilling`).<br>• **Assign workers** links employees to a contractor (a vendor party); taking a worker off the list returns them to the mill's own staff<br>• **Prepare bill** (`hr.payroll.run`) for a finished period of up to two months: man-days from the workers' attendance × the rate, or piece work by unit × the rate per unit<br>• Optional sales tax on services, and income tax withheld u/s 153(1)(b) on the amount before sales tax<br>• Periods of one contractor cannot overlap<br>• **Approve** (`hr.payroll.approve`, a different person) posts *Dr Contract labour / Cr contractor, Cr WHT payable*; the contractor is paid through **Accounts → Payments**<br>• **Print** gives the bill (DRAFT until approved) |
| Expense categories | **Accounts → Expense categories**: the standard ones (travel, fuel, meals, repairs …) and their ledgers are installed the first time; accounts add more. A category's ledger must be an expense ledger for an expense (income for income). Only claimable categories appear on claims. |

**Not done:** employees cannot yet submit their own claims from the phone app; HR enters them. Expense budgets and recurring expenses are phase 11.

## Tested
`tests/Feature/Hr/ClaimsAndContractorsTest.php`, 12 tests:
* **Claims:** the full path with its postings, the payable back to zero and the books balanced; receipt, date, category and claimant rules; refusal.
* **Contractor bills:** man-days with withholding and posting; piece work with sales tax; overlapping, unfinished and empty periods; non-vendors; approval by the preparer refused.
* **Screens:** which roles open each screen; a claim with a receipt taken on screen and moved through check, approval, payment and the voucher; rule errors shown on the form; workers linked, a bill prepared, approved and printed, then the worker unlinked; categories against the right ledger only.

The role and Super Admin screen sweeps also open the three new screens.

Results:
- PostgreSQL and MariaDB both pass.
- Pint is clean.
- Larastan reports no errors.
