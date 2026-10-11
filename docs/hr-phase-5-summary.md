# HR module — Phase 5 summary: wages engine, piece work, advances and loans

Plan: [hr-phases-5-12-plan.md](hr-phases-5-12-plan.md).

## Built
| Area | What exists |
|---|---|
| Wages engine | `WageEngine::calculate(employee, 'Y-m')` returns one employee's month from the true records:<br>• attendance (final punch status, leave, holidays, weekly offs)<br>• the effective-dated salary structure<br>• piece work, advances and the payroll settings<br>It stores nothing; the payroll run (phase 7) snapshots the result. Every line carries its basis, e.g. *"27.5 paid days of 30 (monthly rate Rs 30,000.00)"*, and warnings are returned with the result. The calculation is a chain of steps, which phase 6 extends |
| Monthly salary | Calculated day by day: the structure in force each day × the paid share of the day ÷ calendar days. A mid-month increment, a joining date and an exit date are therefore exact. Holidays and weekly offs are paid. Absence, unpaid leave and days with a missing punch are loss of pay (the warning says so) |
| Daily wage | Day rate × days present (half days 0.5) + paid leave. Public holidays are paid only when the setting says so; weekly offs are unpaid |
| Overtime | 2 × the hourly rate (Factories Act s. 47), in 30-minute blocks. The hourly rate is Basic ÷ 208 h for monthly staff, or the day rate ÷ shift hours for daily staff |
| Holiday work | A day worked on a holiday or weekly off is paid 2 × in total: the extra day rate is added |
| Piece rate | Rate per unit (bag, maund, ton…) from the salary structure × **piece-work entries**:<br>• typed in, or taken from a gate entry (bags) or a milling batch (maund)<br>• split equally across the gang, with the remainder to the first person<br>• each source counts once per employee; append-only, mistakes offset by a negative correction<br>Units without a rate are reported, never paid by guess |
| Advances and loans | Request (HR) → approve or refuse (a different person with `hr.payroll.approve`, never the employee) → pay out from cash or bank (posts *Dr Staff advances / Cr cash-bank*) → recovered:<br>• by payroll instalments, oldest first, never pushing net pay below zero (warning)<br>• or by cash repayments (posts *Dr cash / Cr Staff advances*)<br>Closed automatically at a zero balance. The recovery schedule shows planned vs recovered per month |
| Settings | Effective-dated, append-only `hr_settings`:<br>• OT multiplier, hours per month, OT block, holiday multiplier, paid holidays for daily staff<br>• the phase 6 statutory figures (minimum wage, EOBI, PESSI, fine cap, s. 149 slabs)<br>The **HR → Payroll settings** page shows each value, its source and date, and marks unconfirmed defaults. Changes need `hr.payroll.approve` and a source |
| Screens | **HR → Advances and loans** (request, approve / refuse, pay out, cash repayment, schedule); **HR → Piece work** (record, from a gate entry, from a milling batch, correct); **HR → Payroll settings** |
| Accounts | New system ledgers for payroll: staff advances, salary / overtime / bonus / EOBI / PESSI expenses, salaries payable, s. 149 tax payable, EOBI and PESSI payable, other staff deductions |

## Tested
15 tests in `tests/Feature/Hr/WagesTest.php`:
* **Monthly:** proration with absence, unpaid leave and a half day; a mid-month increment; a mid-month joiner; a missing punch.
* **Overtime and holidays:** overtime blocks; holiday work; a changed multiplier applying only from its date; bad or unauthorised setting changes refused.
* **Daily wage:** with paid leave and holidays (by setting); daily overtime; a run from real punches.
* **Piece rate:** gang split, the same source refused twice, correction, unpriced units reported, the table append-only at the database, permission.
* **Advances:** maker-checker, disbursement voucher, recovery in payroll, instalment cut to available pay; cash repayment, schedule, closing at zero; self-approval and bad requests refused.
* **Screens:** role access to all three screens; request on the screen, approve from the list; change a rate on the settings page; record gang work.
