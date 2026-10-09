# HR module — Phase 1 plan: employee master, documents, salary structures, permissions

Branch `hr-phase-1` (from `access-security`). Later HR phases follow the module brief's list (attendance → … → hardening).

## (a) Folder structure
```
app/Domain/Hr/                 EmployeeService (create, update, notice, clearance, exit), SalaryStructureService,
                               EmployeeDocuments, EmployeeCodes, HrInstaller (default components / departments)
app/Domain/Approvals/Actions/  EmployeeBankAccountChange (maker-checker, like party bank accounts)
app/Models/Hr/                 Department, Designation, Employee, EmployeeDocument, ClearanceItem,
                               SalaryComponent, SalaryStructure, SalaryStructureLine
app/Policies/Hr/               EmployeePolicy, SalaryStructurePolicy, HrMasterPolicy
app/Filament/Resources/Hr/     EmployeeResource (+ Documents, Salary history, Clearance relation managers),
                               DepartmentResource, DesignationResource, SalaryComponentResource
app/Console/Commands/          HrDocumentExpiryCommand (hr:document-expiry, daily)
database/migrations/           2026_10_17_000100_create_hr_tables.php
tests/Feature/Hr/              EmployeeTest, SalaryStructureTest, HrAdminTest; tests/Support/HrFixtures.php
```

## Data model (UUID keys, entity_id + row-level security, created_by, version, timestamps)
| Table | Columns (main) | Notes |
|---|---|---|
| `departments` | code, name, status | unique per entity |
| `designations` | name, status | unique per entity |
| `employees` | code (`KNL-EMP-0001`), person_id → `persons`, photo_file_id → stored files, department_id, designation_id, employment_type (permanent / contract / daily_wage / piece_rate), status (active / on_notice / exited), joining_date, date_of_birth*, address*, emergency contact, **aadhaar\*** + blind index + last 4, **pan\*** + blind index + last 4, **bank account\*** + blind index + last 4, IFSC, bank name, holder, notice_period_days, notice_given_on, last_working_day, exit_reason, exited_on, archived_at | * encrypted; Aadhaar and PAN unique per entity through the blind index; bank details change only by maker-checker |
| `employee_documents` | kind (aadhaar / pan / id_proof / contract / bank_proof / photo / other), title, file_id, valid_until, status (active / superseded), superseded_by | files in the existing private file store (sha256); never deleted |
| `employee_clearance_items` | item, done_at, done_by, note | created from a checklist when notice is given |
| `salary_components` | code, name, kind (earning / deduction), is_statutory, status | defaults per entity: BASIC, HRA, CONV, SPECIAL, PF, ESI, PT, TDS, ADV |
| `salary_structures` | employee_id, effective_from, pay_basis (monthly / daily / piece_rate), reason, supersedes_id | **append-only** (DB trigger); a change is a new row |
| `salary_structure_lines` | salary_structure_id, salary_component_id, amount_paise, unit | **append-only**; monthly amount, daily rate, or per-unit rate |

## (b) Assumptions
1. **Reuse, not duplicate:** an employee is a `person` (name, phone, email already live there). Login accounts already belong to persons, so an employee's ERP login is the account of the same person in the same entity. No second user table.
2. The app uses its own permission catalogue (not spatie). The ten `hr.*` permissions are added, plus **`hr.employee.sensitive.view`** for unmasked Aadhaar / PAN / bank / date of birth, because the brief requires "explicit permission" and none of the ten fits. Every reveal is audited.
3. **New role template `HR_MANAGER`** (2FA required): employees, salaries, payroll run, attendance override, documents. It is created in existing entities by `access:sync`. Accountant gains employee / salary view, payroll approval and document generation. Owner and Super Admin get everything. Supervisor gains `hr.employee.view`.
4. **Employee bank account changes go through maker-checker** (HR requests; someone with `hr.payroll.approve` approves), the same as party bank accounts. Salary goes to that account, so this is the usual payroll-fraud route.
5. Salary structure changes are direct for `hr.salary.edit` holders but never overwrite: each change is a new effective-dated version with a reason, and old versions stay visible. Maker-checker on salary changes can be added when payroll approval exists (phase 7).
6. Money is integer paise (as in the rest of the system). Calculation rules (allowance %, PF / ESI) come in phase 6; phase 1 stores amounts per component.
7. The shift link is added in phase 4 (shifts table). The final settlement statement is computed in phases 5 and 7 and printed by the document engine in phase 8. Phase 1 covers notice, the clearance checklist and exit. On exit, the person's logins in that entity are revoked.
8. Document expiry reminders reuse the alert system (30 / 7 / 0 days before), like scale certificates.
