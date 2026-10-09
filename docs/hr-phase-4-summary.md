# HR module — Phase 4 summary: leave, holidays, shifts, overtime, worked days, biometric import

Plan: [hr-phase-4-plan.md](hr-phase-4-plan.md). API: [api/openapi.yaml](api/openapi.yaml) (`/leave/me`, `/leave/requests`, `/leave/requests/{id}/withdraw`).

## Built
| Area | What exists |
|---|---|
| Shifts | **HR → Shifts**: start / end in IST (a shift ending before it starts crosses midnight and counts for the day it started), break, late / early grace, full-day and half-day minutes, overtime threshold, weekly offs. Employees get a shift on their form. Default shift GEN 09:00–18:00, 1 h break, Sunday off; staff with no shift use the same rule |
| Holidays | **HR → Holidays**: one per entity and date (national / festival / local) |
| Leave types | **HR → Leave types**: CL 7, SL 7, EL 12 (carry up to 30), LWP unpaid. **These quotas are examples: the owner or accountant must check them against the state's Shops & Establishments / Factories Act rules** |
| Balances | Append-only **leave ledger** (opening / accrual / taken / reversal / carry forward / lapse / adjustment, half days). The balance is the sum of the entries and is never an editable number. The employee page has a **Leave** tab with balances, entries and **Adjust balance** (a reason is required) |
| Requests | HR enters leave for someone (**HR → Leave → New**), or the employee uses the app (`POST /leave/requests`, own balances at `GET /leave/me`, withdraw). Days count only working days (no holidays or weekly offs). Half days are single days. Overlapping requests and requests spanning the year end are refused |
| Approval | Approve / Reject / Cancel on the leave list. Needs `hr.leave.approve` (Supervisor, HR Manager, Owner, Super Admin) and never your own leave. Paid leave needs enough balance at approval. Approval posts "taken"; cancelling approved leave posts a "reversal" that gives the days back |
| Year end | `hr:leave-year-end {year}`, scheduled 1 Jan 00:30 IST. Carries forward up to each type's limit and lapses the rest. It runs once per year: running it again adds nothing |
| Worked days | `AttendanceCalculator` works per employee and date range. It uses each punch's **final** status, so flagged punches wait for review and rejected punches never count. First in → last out. The break is deducted only from a full day. Per day it gives P / ½P / A / L / ½L / ½P ½L / HO / WO / MO (missing out-punch, unpaid until fixed). It also gives late, early-leaving and overtime minutes, and notes for work on a holiday or weekly off, too-short days and days not employed. Nothing is stored; payroll will snapshot it when a month is locked |
| Attendance register | **HR → Attendance register**: month × employee grid with codes and totals (present, leave, absent, late, OT), with a department filter. The CSV export is audited |
| Biometric import | **HR → Attendance → Import from biometric file**. CSV columns are employee code, IST date-time (`2026-09-07 08:58`, `07/09/2026 08:58` or `07-09-2026 08:58`) and an optional in / out. Step 1 previews the result; step 2 imports. Punches are stored with source "import" and reason "imported". A missing in / out is inferred from the order and marked "direction_inferred". The file is kept privately with its SHA-256. Each row has a fixed ID, so the same file twice, or a duplicate line, adds nothing. Needs `hr.attendance.override` and is audited |

## Tested
* 19 new tests (WorkedDaysTest 5, LeaveTest 14):
  * **Worked days:** a realistic week covering on time, late with overtime, half day, missing out-punch, CL, holiday, weekly off and absent; a night shift across midnight; counting by final status (flagged → accepted → rejected); half-day leave plus half day worked; work on a weekly off; days before joining and future days.
  * **Leave:** working-day counting around a holiday and a Sunday; balance checks at approval; LWP needs no balance; refusals for overlap, multi-day half day, across the year end, all-off dates, own leave and missing permission; cancel by reversal; ledger append-only at the database.
  * **Year end:** carry with the limit, lapse, idempotent, and the following year still closes; the artisan command.
  * **Biometric import:** formats, header, unknown code, bad date, in-file duplicate, inferred directions, file hash, re-import adds nothing, permission.
  * **Leave API:** me, store, overlap 422, withdraw.
  * **Screens:** role access to leave / shifts / holidays / leave types / register; leave entered by HR and approved by a supervisor; the import wizard; the Leave tab and balance adjustment; creating a night shift; the register with codes and its CSV export.
* **Full suites:** see below. Larastan, Pint and the OpenAPI contract test are clean.

## Caught before release
* **Year end:** the "already closed" check also matched the carry-forward that arrives in the *next* year. Next year's closing would therefore have been skipped, and its leave never lapsed or carried. It now looks only at outgoing entries, and a test closes two years in a row.
* **Import wizard:** a single uploaded file is the file object itself, not a list, so the wizard read an empty file and always said "Nothing to import". Fixed, and covered by a screen test.
* **Break rule:** deducting the lunch break from a 09:00–13:00 half day made it "too short". The break now comes off only above full-day minutes.

## Open decision (unchanged)
* `approval.decide` is held only by Owner and Super Admin, so manual attendance and salary-account changes are approved by the Owner. Leave is not affected: it uses its own `hr.leave.approve`.

## Next: phase 5
Payroll set-up on top of the worked days: pay components and statutory settings (PF, ESI, PT, TDS), turning a month's register into earnings with overtime, LWP and absence deductions, and locking the month by snapshot.
