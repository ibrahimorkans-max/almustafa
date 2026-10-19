# Approvals for Super Admin, Rs format, printed records, complete report, action chatbot

## No second person for the Owner and the mill's Super Admin

`App\Domain\Approvals\SelfApproval::allowed()` is true for the Owner and every mill's Super Admin.

* **Their requests are applied at once.** `ApprovalService::submitFor()` approves a request raised by them
  immediately, as their own decision, with the note "Applied directly: requested by the Super Admin (no second
  approval needed)". This covers role changes, payments (both stages above the limit), bank accounts, voucher
  reversals, period unlock, stock adjustments, rate changes, credit overrides, manual attendance and salary
  accounts. The request and its decision stay in the history and the audit log.
* **They may decide what they made themselves:** saudas, claims, contractor bills, payroll runs, advances and pay
  adjustments they requested, reimbursements they submitted, and weight checks of weights they recorded.
* **Manual weight** (`weighment.override`) may now be entered by the mill's Super Admin as well as the Owner, and
  is recorded at once (still flagged as manual and sent to the weight checks).
* **Still a second person, on purpose:** a new sign-in device (that is the sign-in check itself), and money or
  leave for oneself as an employee (own advance, pay adjustment, reimbursement, leave, attendance review).
* **Staff** (accountant, HR, supervisor…) keep maker-checker exactly as before.
* Screens say "Done: applied at once" instead of "Sent for approval" when that is what happened (`ApprovalNotice`).

## Money format

`Money::format()` / `grouped()` use lakh grouping: **Rs 1,25,000.00**, Rs 12,34,567.89, Rs 1,00,00,000.00.
Every screen, PDF and message goes through it; the remaining ₹ signs became Rs. Excel exports show the same
grouping with a number format, and the values stay numbers.

## Printed records and the second-person signature

New printable documents (`RecordDocuments`, layout `documents/record.blade.php`), each with a **Print** button
on the record page and on each table row, and a **Print** link in the "Created" message right after saving:

| Record | Paper | Signature boxes |
|---|---|---|
| Any accounting voucher (journal, receipt, payment, contra…) | A4 voucher with Dr / Cr lines and amount in words | Prepared by (name) · **Checked by (second person)** · Approved by |
| Expense / other income (posted) | A5 expense voucher or income receipt | Prepared by (name) · **Checked & posted by (second person)** (name) · Received / paid by |
| Claim | A5 claim note | Raised by (name) · **Checked by (second person)** · Party |
| Sauda (purchase or sale) | A4 contract: quantity, rate, value, delivery, quality, brokerage | Made by (name) · **Approved by (second person)** (name) · Seller / buyer |
| Dispatch order | A5 order | Ordered by (name) · Store keeper · Driver / buyer |
| Stores requisition | A5 issue slip | Requested by (name) · Approved by (name) · Received by |
| Production batch | A4 batch report with outturn against standard | Supervisor (name) · **Checked by (second person)** · Mill manager |

Existing papers gained the second-person box: payment voucher (with the names of who prepared and who approved
it), purchase bill, sales invoice, financial reports and ledger statements. Printing goes through
`GET /admin/print/{type}/{id}`: the record is looked up inside the working mill, the person needs the right to
see it (e.g. `ledger.view` for vouchers), and every print is audited (`document.printed`).

## Complete business report

**Administration → Complete business report**: choose today, yesterday, this week, this month, last month, this
financial year or any dates. The page shows the key figures; **Download PDF** (A4 landscape) or **Download Excel**
(one sheet per part) gives everything at once, without the audit log:

key figures (income, purchases and expenses, gross profit, net profit or loss, payroll cost, cash and bank,
receivables, payables, stock value, vehicles in and out, weight checks) · profit and loss · sales invoices ·
expenses and other income · purchase bills · payments · cash flow · customers who owe · suppliers owed · stock
value · vehicles in · vehicles out · production yield · payroll cost by department · claims.

Needs `report.view` and `ledger.view` (Owner, Super Admin, accountant). Downloads are audited.

## Action chatbot (Assistant)

**Assistant** at the top of the menu, for everyone signed in. Two modes, chosen automatically:

* **AI mode** when `ANTHROPIC_API_KEY` is set: normal sentences in English or Roman Urdu ("kitni gaariyan andar
  hain?", "record 1,200 for tea from cash"). Model `RICEMILL_ASSISTANT_MODEL` (default `claude-sonnet-5-5`).
  The question, the earlier turns of the chat and the tool results are sent to Anthropic.
* **Command mode** (offline, no key): short commands; "help" lists them. If the AI service fails, the chat falls
  back to commands and says so.

What it can do (each only if the person's role allows it):

| Ask / command | Does |
|---|---|
| profit this month · income today | Sales, other income, purchases, expenses, gross and net profit or loss, vehicles in/out, link to the complete report |
| stock · stock of 1121 | Stock in hand by product and godown |
| vehicles inside | Vehicles in the mill, their stage and the weight they need next |
| live weight | The weighbridge reading now |
| pending approvals | Waiting requests with a short reference |
| find Aslam | Parties by name, code, city or phone |
| open payments · new sauda | Links to any screen of the system (and its "new" form) — reaches every feature |
| arrived MNA-21-1234 sauda 0001 400 bags | Registers a vehicle arrival * |
| weigh MNA-21-1234 | Records the stable weighbridge reading as its gross or tare * |
| ok weight MNA-21-1234 · query weight … : note | Weight check * |
| approve 01a2b3c4 · reject 01a2b3c4 because … | Decides a request * |
| expense 1200 for meals: tea | Records and posts an expense or income, with a Print link * |

\* Changes are shown as a card and run only after **Confirm**; they run as the person, through the same services
and rights as the screens, and are audited (`assistant.action`).

## Settings

```
RICEMILL_ASSISTANT=true
ANTHROPIC_API_KEY=
RICEMILL_ASSISTANT_MODEL=claude-sonnet-5-5
```

## Update: Print on every screen, chatbot for every feature

### Print button on every screen

Every list screen and every record screen has a **Print** button at the top (a render hook in
`AdminPanelProvider` plus `App\Livewire\PrintScreenHook`, so no screen has to declare it):

* a **list** prints exactly what the screen shows: its columns and every record matching the current search and
  filters, in the current order (up to 2,000 rows), with the search and filters written on the paper;
* a **record** prints its own paper when it has one (weighment slip once weighed, purchase bill, payment voucher,
  sales invoice, voucher, expense, claim, sauda, dispatch order, issue slip, production batch) and otherwise a record
  sheet of everything the screen shows.

Every paper has the "Prepared by" and "Checked by (second person)" boxes. Prints are audited (`document.printed`).

### Chatbot: every feature

New general actions reach every screen of the system the person may open (`App\Domain\Assistant\Screens`):

| Ask / command | Does |
|---|---|
| list payments · search saudas for Aslam | Lists or searches the records of any screen, newest first, with Open and Print links |
| show sauda 0001 · show party AMR-F001 | One record in full, with Open and Print links (its own paper or a record sheet) |
| new party · new employee | The fields of any "new" form; with values (AI mode) a link to the form already filled in (`?prefill[...]`), which the person checks and saves |
| lab LRA7788 moisture 15.5 fm 1 grade A · accept lab … · reject lab … : note · unloaded … into G1 · slip … · exit … | Gate steps after weighing * |
| sauda 500 maund 1121 from Aslam at 4200 | Purchase sauda (confirmed at once for the Owner / Super Admin) * |
| pay Haji Riaz 250000 by ibft | Payment on account (released at once for the Owner / Super Admin) * |

\* After Confirm. Creating anything else (parties, products, employees, leave, requisitions…) goes through the
filled-in form, so every check of that screen applies.
