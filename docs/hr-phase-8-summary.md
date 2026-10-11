# HR module — Phase 8 summary: documents engine

Plan: [hr-phases-5-12-plan.md](hr-phases-5-12-plan.md). Previous: [hr-phase-6-7-summary.md](hr-phase-6-7-summary.md).

## How it works
One engine makes every HR document.

**Building and issuing**
- Each document type reads its record and builds a **language-neutral content snapshot**. The snapshot holds the figures and label keys, never printed text.
- A record that is not final yet (for example a calculated payroll) can only be **previewed**, watermarked DRAFT. Nothing is stored.
- A final record is **issued once**:
  - numbered with its own number (payslip PSL, advance ADV, payment SPAY, payroll PR) or a gap-free series (certificate CERT, bonus slip BNS, attendance summary ATS);
  - the snapshot is frozen;
  - the original PDF is stored with its SHA-256;
  - a verification code and QR are printed;
  - the template version used is recorded.
- `generated_documents` is append-only in the database.

**Afterwards**
- The **original** can be downloaded byte for byte; its hash is checked first.
- Any other layout, language, paper or format is a **reprint**. It is rendered from the same frozen snapshot, so the figures cannot differ, and is watermarked *DUPLICATE #n* and counted.
- A **correction** builds the content again from the record:
  - if nothing changed, it is refused;
  - otherwise a new revision (`… R1`) replaces the current one, which stays visible;
  - the verification page then shows the old revision as *Replaced by … R1*.

**Logging**
- Every issue, view, download, reprint and unmasking is written to `document_access_log`, which is append-only.

## Options
| Option | Choices |
|---|---|
| Layouts (per document type; 4 standard templates created on first use, Classic the default) | Classic; Compact; Detailed (adds the basis of every line, e.g. *"26 paid days of 30"*); Minimal |
| Paper | A4; A4 landscape (payroll register); A5; **half-A4** (two slips per A4 sheet with a cut line); thermal 80 mm and 58 mm |
| Languages | English; Urdu (right to left, Lateef font); bilingual (English with Urdu labels) |
| Digits | Western or Urdu (۱۲۳) |
| Amount in words | English (international system) and Urdu (lakh / crore, e.g. *ایک لاکھ پچیس ہزار پچاس روپے اور پچاس پیسے صرف*) |
| Toggles (template default, per print override) | employer contributions, tax year to date, leave balance, attendance calendar, advance / loan balance, QR, stamp, signatures |
| Output | PDF; printable HTML (QR as SVG); PNG (needs `pdftoppm`, see below); ESC/POS text for receipt printers; password-protected PDF (last 5 digits of the CNIC, or a PIN); Excel for the payroll register |

**Sensitive fields:** CNIC and bank account are stored masked in the snapshot. They are printed in full only for users with `hr.employee.sensitive.view`, who must ask for it. This is audited. A certificate for a bank can be issued with the full CNIC on the original.

**Templates:** HR → Document templates (needs `hr.documents.template.manage`) sets:
- the line under the title, terms, footer;
- accent colour, NTN on/off;
- default toggles;
- the company stamp and the signature image.

Every change is a new version. Documents already issued keep their version.

## Document types
| Type | Source | Final when |
|---|---|---|
| Payslip / wage slip (title follows the pay basis) | payroll item | the month's payroll is finalized |
| Advance / loan voucher with recovery schedule | advance | paid out |
| Bonus / allowance slip | approved bonus or other earning | approved |
| Salary payment receipt (cash or cheque, notes the acknowledgement) | salary payment | always |
| Attendance summary with day calendar | locked attendance of the payroll | payroll finalized |
| Payroll register (A4 landscape, also Excel) | payroll run | finalized |
| Salary certificate, employment certificate | employee | employee not exited (a new certificate each time) |

Reimbursement vouchers and contractor bills come in phase 10. The final-settlement statement follows when exit settlement is calculated.

## Screens
- **HR → Payroll → month:**
  - **All payslips**: issues the missing ones and gives one PDF, two per sheet. Before finalization it gives DRAFTs. Failures are listed.
  - **Payroll register** as PDF, or as Excel.
  - Per employee: **Payslip**, **Attendance summary**, **Payment receipt**.
- **HR → Advances**: **Voucher**.
- **HR → Bonuses and deductions**: **Slip**.
- **Employee page**: **Certificate** (employment or salary, with purpose).
- **HR → Documents**: every issued document. Actions: download the original, reprint (layout, language, paper, format, toggles, password, full identifiers), issue a correction. The view shows the template version, hash, verification code and the full access log.
- **/verify** (public): for HR documents, shows only the document, issue date and validity.

## Deployment
- `poppler-utils` is now installed by `infra/vps/provision.sh`.
- `RICEMILL_PDFTOPPM` can point elsewhere.
- Preflight warns when it is missing. PDF works without it.

## Tested
`tests/Feature/Hr/DocumentsTest.php`, 13 tests:
* **Urdu:** amounts in words and digits.
* **Drafts:** draft only before finalization; issued once, with the payslip's number.
* **Same figures everywhere:** net pay and tax are identical in all 4 layouts × 3 languages.
* **Originals and reprints:** the original is unchanged; reprints are DUPLICATE and counted, and the access log records each one.
* **Masking:** CNIC and bank account stay masked without permission; unmasking needs it and is logged.
* **Verification:** a genuine code passes and a wrong one fails. A correction issues R1 and the old revision shows as replaced; correcting twice is refused.
* **Numbering:** the CERT series has no gaps. `generated_documents` refuses UPDATE at the database.
* **Formats:** thermal PDF; ESC/POS lines within 32 columns; HTML with an SVG QR; half-A4 on A4; password PDF; PNG, or a clear message when the tool is missing.
* **Templates:** permission checked; colour validated; new versions apply to new documents only; default switch.
* **Bulk:** all payslips with one forced failure, printed two per sheet from the originals.
* **Registers:** the payroll register and the attendance summary with its calendar.
* **Self-service:** an employee opens only their own documents.
* **Screens:** role access to both screens; payslip from the payroll table; all payslips; Excel register; reprint from the Documents list.

Results:
- PostgreSQL and MariaDB both pass.
- Pint is clean.
- Larastan reports no errors.
