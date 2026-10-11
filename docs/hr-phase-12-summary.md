# HR module — Phase 12 summary: hardening

Plan: [hr-phases-5-12-plan.md](hr-phases-5-12-plan.md). Previous: [hr-phase-11-summary.md](hr-phase-11-summary.md). This closes the HR plan (phases 1–12).

## Privacy review
Every place the HR and accounts work of phases 5–11 shows or sends personal data was checked.

| Where | Finding | Result |
|---|---|---|
| **Alerts list** | Showed every alert to anyone with stock or production access, including payroll alerts naming employees with their overtime, pay below the minimum wage and payroll totals | **Fixed.** Each person sees only the alerts raised for permissions they hold (and those raised for no permission). HR and accounts can now open the list for their own alerts. The menu count and the dashboard count follow the same rule. |
| **Documents showing pay** (payslip, salary certificate, advance voucher, bonus slip, cash salary receipt, payroll register) | `hr.documents.generate` alone opened anyone's document. The built-in roles were safe, but a custom role given documents without salaries would see everyone's pay | **Fixed.** Someone else's pay document, and issuing, previewing, correcting or bulk-generating one, now also needs `hr.salary.view`. An employee always sees their own. Letters without pay, claim vouchers and contractor bills are unchanged. |
| **SMS sharing** | Without an SMS provider the full message (the link that opens the document) and the phone number went to the main application log for 30 days, through a config key that did not exist | **Fixed.** The sender gets the text on screen, as before. The log records only that an SMS was prepared, the document, and the number masked (`+923*****321`), on the configured SMS log channel. |
| `/verify` | HR documents show only number, issue date and whether still valid | Fine. |
| Share links `/d/{token}` | Random 48-character token, only its hash stored, limits and expiry, `no-store`, `noindex`, rate-limited | Fine (phase 9). |
| Phone app `/api/v1/documents/*` | Own documents only; someone else's refused with 403 | Fine; now also under the pay rule above. |
| Payroll bank file (IBANs and net pay) | Only `bank.view` (accounts, owner) | Fine. |
| Statutory returns (full CNIC) | Only payroll preparers and approvers | Fine. |
| Bulk documents (ZIP of payslips) | Only `hr.documents.generate`; now also `hr.salary.view` for pay documents | Fine. |
| Claim and expense receipts | Downloadable only from screens their viewers may open | Fine. |
| Financial report downloads | Audited | Fine. |

## Authorization matrix
`tests/Feature/Access/HrAccountsMatrixTest.php` signs in as each of nine roles and opens 23 HR and accounts screens. The expected matrix is written out in the test, so any change of access shows up as a test failure that names the cell. It also tests the alert rule, the pay rule with a custom "document clerk" role, and the bulk limits. The existing sweeps (`RoleAccessTest`, `SuperAdminAccessTest`) still check that every screen answers 200 or an explaining 403, never an error.

## Performance
* **Indexes** (migration `2026_10_27_000100_add_report_indexes`): voucher lines by entity and date (profit and loss, cash flow); stock ledger by entity and time, and by entity, movement and time (stock on a date, milling cost, dashboard); generated documents by entity, type and date (undelivered payslips).
* **People dashboard**: today's attendance is worked out for every employee, and the widget refreshes every 2 minutes. It is now cached for a minute per mill and day.
* **Overtime alert**: the previous six months are read in one query per month instead of one per employee per month.
* **Bulk generation**: at most 1,000 documents in one run, and two runs at a time per mill. A run untouched for two hours (a worker that died) no longer counts.

## OpenAPI
`docs/api/openapi.yaml` describes `GET /api/v1/documents/me` and `GET /api/v1/documents/{document}/pdf`; `OpenApiContractTest` keeps the spec and the routes in step.

## Tested
* `HrAccountsMatrixTest` (4 tests) and an SMS check added to `DeliveryTest`.
* PostgreSQL and MariaDB both pass, as do Pint and Larastan.
