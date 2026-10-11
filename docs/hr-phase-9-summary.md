# HR module — Phase 9 summary: delivery, bulk generation, self-service

Plan: [hr-phases-5-12-plan.md](hr-phases-5-12-plan.md). Previous: [hr-phase-8-summary.md](hr-phase-8-summary.md).

## Built
| Area | What exists |
|---|---|
| Share links | **HR → Documents → Send / share** (needs `hr.documents.share`).<br>• A link is a random 48-character token; only its SHA-256 is stored<br>• It stays open 1 hour to 30 days, optionally for at most N openings, and can be revoked at any time (**Shared links** tab)<br>• A payslip link can open a copy protected with the last 5 digits of the employee's CNIC<br>• Only the current revision can be shared; a link to a document replaced later stops opening<br>• Public route `/d/{token}`: `no-store`, `noindex`, `no-referrer`, limited to 30 requests a minute; a closed link shows a bilingual "no longer open" page |
| Channels | • **E-mail**: the original PDF attached, plus the verification details<br>• **WhatsApp**: a `wa.me` link with the message and the share link, sent from the HR phone (no provider needed)<br>• **SMS**: until an SMS provider is configured, the sender gets the text on screen to send from the HR phone (since phase 12 the log records only that an SMS was prepared, with the number masked and no link)<br>• **Link**: copied by hand<br>Mobile numbers are normalised to +92 and stored encrypted |
| Access log | Every share, opening, refusal (expired, revoked or replaced) and revocation goes into `document_access_log`, next to issue, download and reprint |
| Bulk generation | **HR → Bulk documents** (queue).<br>• Covers the payslips or attendance summaries of a finalized month, or employment / salary certificates, by department or for everyone<br>• Progress is saved after each record, so a retry continues where it stopped (3 tries with back-off)<br>• Records that fail are listed with the reason, and **Retry the failed ones** is available<br>• The result is one PDF to print (originals; half-A4 two to a sheet) and a ZIP of the originals named by employee code<br>• The job runs in the mill and as the person who started it, so permissions and logs stay true<br>• The payroll page also has **Payslips on the queue** for large payrolls |
| Automation | • Payroll setting **Issue every payslip automatically when a payroll is finalized** (off by default) queues the payslips at finalization<br>• Daily `hr:salary-ack-reminders` (07:45 PKT) raises one alert per cash salary payment still without the signature or thumb after the set number of days (default 2) |
| Self-service | • **My documents** in the panel, for any login linked to an employee: own current documents only, downloads logged<br>• API for the phone app: `GET /api/v1/documents/me` and `GET /api/v1/documents/{id}/pdf`; someone else's document is refused with 403 |

**Not done:** "Managers see their team's documents". The system has no reporting-line data yet, so access stays permission-based (HR and accountants with `hr.documents.generate`).

**Needs a provider:** an SMS or WhatsApp Business provider can be added later behind the same share call.

## Tested
`tests/Feature/Hr/DeliveryTest.php`, 7 tests:
* **Links:** opening limits, expiry, revocation, unknown tokens; every opening and refusal is logged with its reason.
* **Channels:** the protected copy is encrypted; e-mail carries the attachment; bad addresses and numbers are refused; the WhatsApp link format is right.
* **Rules:** only the current revision is shared, only with the permission, and a link to a document replaced later is refused.
* **Bulk:** a batch with one forced failure, the ZIP contents, retry, and no double issue.
* **Automation:** automatic payslips at finalization; the acknowledgement reminder is raised once.
* **Self-service:** API and panel show only one's own documents; HR screens are refused to employees.
* **Screens:** share from the list; bulk generation started on screen.

Results:
- PostgreSQL and MariaDB both pass.
- Pint is clean.
- Larastan reports no errors.
