# HR module — Phase 1 summary: employee master, documents, salary structures, permissions

Plan and assumptions: [hr-phase-1-plan.md](hr-phase-1-plan.md).

## Built
| Area | What exists |
|---|---|
| Employees (**HR → Employees**) | Code `KNL-EMP-0001` (gap-free, never resets); an employee is a `person`, so an existing ERP login of the same person in the entity is linked automatically. Department, designation, type (permanent / contract / daily wage / piece rate), joining date, notice period, emergency contact |
| Privacy | Aadhaar (Verhoeff check digit), PAN, date of birth, address and the salary account are encrypted. Aadhaar and PAN are unique per entity through keyed hashes (the same person may also work for another firm of the group). Screens show the last 4 digits; **Reveal** needs `hr.employee.sensitive.view` and every reveal is audited. The edit form never puts identifiers back in clear. The audit log records identifiers masked and personal fields as "changed" |
| Salary account | Set and changed only through maker-checker (`hr.employee.bank_account.change`): HR requests, someone with approval rights and `hr.payroll.approve` approves. The number is encrypted inside the request; screens and the audit log see the last 4 |
| Documents | ID proofs, contracts, bank proof and photo in private, content-addressed storage (PDF / JPEG / PNG, 5 MB). A new copy supersedes the old one (kept). Identity and bank documents open only with the sensitive permission; downloads are audited; a stored file that no longer matches its fingerprint is refused. `hr:document-expiry` (daily 07:30 IST) alerts 30, 7 and 0 days before expiry, once each |
| Salary structures | Effective-dated versions per employee: monthly (per month), daily (daily rate) or piece rate (per unit, e.g. bag); amounts per pay head in paise. **Append-only** (model guard + database trigger): a raise is a new version, a correction for the same date replaces it from then on, and both stay visible. "Salary history" shows which version is in force; only for `hr.salary.view`. The audit log notes the change without copying pay figures |
| Pay heads | BASIC, HRA, CONV, SPECIAL, PF, ESI, PT, TDS, ADV installed in every entity (`hr:install`; new entities automatically); HR can add more. Calculation rules come in phase 6 |
| Exit | Give notice (last working day from the notice period) → 5-item clearance checklist → mark exited only when cleared. The person's ERP logins in that entity are revoked at once, and the record is frozen. Notice can be withdrawn |
| Permissions and roles | The ten `hr.*` permissions from the brief plus `hr.employee.sensitive.view`. New **HR Manager** role (2FA required). Accountant: employee and salary view, payroll approval, document generation. Supervisor: employee view. Owner and Super Admin: all. `access:sync` now also creates template roles that existing entities are missing |

## Tested
* 28 new tests: master data (codes, encryption at rest, masking, Aadhaar check digit, PAN, age, uniqueness per entity), permissions (HR edits, supervisor views, accountant cannot reveal), audited reveal, exit (notice, clearance, login revoked, record frozen, no duplicate checklist), documents (supersede, private storage, sensitive kinds, audited download, tamper detection, expiry reminders once each), salary account maker-checker, salary structures (effective dating, same-day correction, daily and piece rate, validation, no pay in the audit log, append-only at the database), screens (role access to 4 screens, create / edit / view, masked display, reveal, salary tab visibility, salary / document / notice / exit / bank actions, supervisor read-only), `access:sync` role creation.
* **PostgreSQL:** 510 passed, 1 skipped. **MariaDB:** 498 passed, 12 skipped, 1 failed. The failure was the same-second ordering bug below; after the fix the HR tests (28) pass on both databases. Larastan, Pint and the secret scan are clean.

## Fixed along the way
* The audit logger read `entity_id` from every subject; `persons` (shared across entities) has none, so updating a person failed in development. Models without the column now go to the current entity's chain.
* A new employee did not know the table defaults (notice period 30 days) before being reloaded, so the last working day came out wrong.
* Two salary versions saved within the same second tied on their timestamp (stored to the second), so on MariaDB the older one could win. The time-ordered record id now breaks the tie.
* MariaDB limits index names to 64 characters; the salary-line unique index has an explicit short name.
* Not a code problem: an interrupted MariaDB test run had left the local `ricemill_test` database with a broken InnoDB data dictionary. That throwaway test database was dropped and recreated (the `ricemill` login's grants are per database name and stayed).

## Open decision
* The Accountant role holds `hr.payroll.approve` but no approval rights at all (`approval.decide`), so salary-account changes are approved by the Owner today. Giving accountants `approval.decide` would also let them approve other requests their permissions allow (for example party bank accounts). Left unchanged; the approval screen says who can approve.

## Next: phase 2
Attendance API and mobile / PWA punch flow, geofences per site, employee device binding, offline queue with idempotency keys.
