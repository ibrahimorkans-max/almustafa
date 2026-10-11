# HR module — Phases 6 and 7 summary: allowances, statutory deductions, payroll run and salary payment

Plan: [hr-phases-5-12-plan.md](hr-phases-5-12-plan.md). Phase 5: [hr-phase-5-summary.md](hr-phase-5-summary.md).

Phases 6 and 7 were built together, because the income-tax year-to-date figures and the statutory returns need finalized payroll.

## Phase 6: allowances, bonuses, deductions, statutory
| Area | What exists |
|---|---|
| Allowance rules | Four methods:<br>• a fixed amount per month (prorated by paid days, or not)<br>• a percentage of Basic earned<br>• an amount per day present<br>• a fixed amount if attendance is good (at most N absences or late days)<br>A rule can be limited to departments or employment types, and has effective dates. Setting or changing a rule needs `hr.payroll.approve` |
| Bonuses and deductions | Pay adjustments: bonus, other earning (arrears), fine, other deduction.<br>• Always with a reason; counted only after a **different** person approves<br>• Requests still pending show as payroll warnings<br>• Fines are capped at the fine cap of wages (3 % by default), with a warning<br>• The **festival bonus** creates a batch for everyone on the rolls (a share of monthly Basic, minimum service, optional proration by service), approved in one go<br>• Refused for months already approved or finalized |
| EOBI | Employee share 1 % and employer share 5 % of the EOBI wage base, per employee (`eobi_applicable`, EOBI number) |
| PESSI | Employer 6 %, only while the monthly wage rate is within the coverage ceiling (`pessi_applicable`, PESSI number) |
| Income tax (s. 149) | Salary-tax slabs on the projected tax-year salary (July–June). Tax already deducted in finalized months is taken off, and the rest is spread over the remaining months |
| Minimum wage | A warning when the rate is below the minimum wage (Punjab default) |
| Statutory figures | Every rate, base, ceiling and slab is an effective-dated payroll setting, marked **default — confirm** until someone records the official source. Statutory rates change by notification and must be checked before the first live run |

## Phase 7: payroll run, posting, payment
| Area | What exists |
|---|---|
| Workflow | **draft → locked → calculated → approved → finalized**.<br>• **Lock** snapshots each employee's attendance for the month. From then on, attendance review, manual attendance, leave approval or cancellation, and biometric import for that month are refused<br>• **Calculate** runs the wages engine on the snapshot. Each calculation is a new numbered set of append-only items; earlier ones stay as history<br>• **Approve** needs `hr.payroll.approve` and a person other than the one who calculated<br>• **Unlock** (with a reason) is possible before approval. The next lock takes a fresh snapshot (`lock_no` + 1)<br>• **Finalize** is allowed only after month end |
| Posting | Finalizing:<br>• posts one journal voucher on the last day of the month: *Dr salary, overtime, bonus, EOBI and PESSI expense / Cr salaries payable, s. 149 tax payable, EOBI and PESSI payable, staff advances, other staff deductions*<br>• records the advance recoveries<br>• numbers the payslips (PSL series)<br>The run can never change afterwards |
| Bank transfer | One file per transfer covering everyone still owed who has a valid IBAN. Two formats: generic, and IBFT bulk upload with purpose SALA. Posted as one payment voucher. Staff without an IBAN are listed back. Downloading needs `bank.view`, is audited, and refuses a file whose hash no longer matches |
| Cash and cheque | Cash only up to the salary cash limit (s. 21(m), Rs 25,000 by default), never more than is due. Cheque pay has a cheque number. Each payment posts *Dr salaries payable / Cr cash-bank*. A cash payment is acknowledged with a photo of the employee's signature or thumb impression (stored in the file store, append-only) |
| Statutory reports | From finalized payroll only:<br>• EOBI contributions (PR-01 columns)<br>• PESSI contributions<br>• s. 149 salary-tax statement<br>• wages register and overtime register<br>Viewable on screen, exported to CSV or Excel (XLSX), each export audited. Full CNICs only with `hr.employee.sensitive.view`; otherwise masked. Excel and CSV cells are protected against formula injection |

## Screens
- **HR → Payroll**:
  - month list;
  - month page with the workflow buttons (lock, calculate / recalculate, approve, finalize, unlock);
  - bank transfer file and its download, and a link to the statutory reports;
  - the employee table: paid days, gross, deductions, net, warnings, paid status and payslip number;
  - a details view with every line, its basis, warnings and working;
  - pay cash or by cheque, and record the signature or thumb.
- **HR → Bonuses and deductions**: add one; festival bonus for all; approve, approve a whole batch, or refuse.
- **HR → Allowance rules**.
- **HR → Statutory reports**.

## Tested
`tests/Feature/Hr/PayrollRunTest.php`, 13 tests:
* **Allowances:** paid by rule only to those they apply to.
* **Bonuses and fines:** maker-checker, the fine cap, a festival batch prorated by service.
* **EOBI and PESSI:** both shares, and the ceiling.
* **Income tax:** the slabs and the projection.
* **Payroll run:** the full workflow, a different approver, finalize only after month end, voucher lines, locked attendance refused, items append-only.
* **Advances:** recovery at finalization; unlock and re-lock with a correction.
* **Bank file:** IBAN-only payments, the IBFT format, tamper detection.
* **Cash:** the s. 21(m) limit, never paid twice, the thumb acknowledgement; cheque payment.
* **Statutory reports:** CNIC masking, and refusal for a month not finalized.
* **Screens:** role access; allowance rule entry; adjustments with approval; the whole month on screen, from opening to cash payment and the Excel register.

Results:
- PostgreSQL and MariaDB both pass.
- Pint is clean.
- Larastan reports no errors.

## Open points for the mill
- Confirm the current EOBI, PESSI, minimum wage and s. 149 slab figures, and record their source in **Payroll settings**.
- Enter EOBI and PESSI numbers, and employee IBANs, before the first live payroll.
- Check the bank's bulk-upload template against the IBFT format before the first transfer.
