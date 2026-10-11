# HR module — Phase 4 plan: leave, holidays, shifts, overtime, worked days

Branch `hr-phase-4` (from `main` with HR phases 1–3).

## Data model (UUID keys, entity_id + row-level security)
| Table | Main columns | Notes |
|---|---|---|
| `shifts` | code, name, start, end (may cross midnight), break_minutes, grace_late / grace_early minutes, full_day_minutes, half_day_minutes, overtime_after_minutes, weekly_offs (ISO days), status | `employees.shift_id` links staff to a shift |
| `holidays` | date, name, kind (national / festival / local) | one per entity and date |
| `leave_types` | code, name, paid, annual_days, carry_forward, max_carry_days, half_day_allowed, status | defaults CL, SL, EL, LWP (unpaid); **quotas are examples to be checked against the state's Shops & Establishments / Factories Act rules by the owner or accountant** |
| `leave_requests` | employee, type, from, to, half_day (none / first / second), days (excludes holidays and weekly offs), reason, status (pending / approved / rejected / cancelled), decision by / at / note | |
| `leave_ledger` | employee, type, year, kind (opening / accrual / taken / reversal / carry_forward / lapse / adjustment), days (signed, half days), request_id, note | **append-only**; balance = sum, never an editable number |

## Rules
* **Leave:** request (HR on someone's behalf in the admin panel, or the employee through `POST /leave/requests`). Approval needs
  `hr.leave.approve` (Supervisor, HR Manager, Owner, Super Admin); never your own. A paid type needs enough balance at approval.
  Approving posts "taken"; cancelling approved leave posts a "reversal" (with reason). Days exclude holidays and the shift's weekly offs.
  Overlapping requests are refused.
* **Year end** (`hr:leave-year-end {year}`): carry forward up to each type's limit, lapse the rest (ledger entries, once per year).
* **Worked days** (`AttendanceCalculator`, per employee and date range): uses each punch's **final** status (valid, or accepted on
  review). Punches are assigned to the shift day: night shifts crossing midnight count for the day they started. The first in and the
  last out give worked minutes (minus break). Per day the result is present, half day, absent, leave (type, full / half), holiday or
  weekly off, plus late minutes, early-leaving minutes, overtime minutes and notes (missing out-punch, punch on a holiday).
  Holidays and weekly offs worked are shown as such for later pay rules. Nothing is stored yet: payroll (phases 5–7) locks a month by
  snapshotting it.
* **Attendance register** (HR → Attendance register): month × employees grid (P / ½ / A / L / H / WO) with totals, late and overtime;
  CSV export (audited).
* **Biometric / gate-PC import:** CSV (employee code, date-time, optional in / out). Preview first, then import as punches of source
  "import" (reason "imported"). The file is kept in the private store with its SHA-256, and rows are idempotent (the same file twice adds
  nothing). Missing directions are inferred in time order and marked as such. Needs `hr.attendance.override`.

## Permissions
`hr.leave.request` (own leave, all staff roles like punching), `hr.leave.approve` (above). Shifts, holidays, leave types and
balance adjustments use `hr.employee.edit`.
