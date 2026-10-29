# MillNexa Rice Mill ERP: user manual

How to use the system day to day, screen by screen. Each section names the menu where the screen is, and
who normally uses it. What you see depends on your role: a button or menu you do not have the right for is
simply not shown. Detailed design notes for each area are in the other files in this folder; they are
named at the end of each section.

Contents

1. Signing in, devices and the top bar
2. Masters and codes
3. Gate: tokens, weighing, lab, unloading
4. Procurement: saudas and negotiation
5. Stock and production
6. Sales and dispatch
7. Accounts: bills, payments, books, roznamcha, bank e-mail
8. Party statement and sharing
9. Labour contractors
10. HR, attendance, payroll and daily wages
11. Reports
12. Printing and paper
13. Messages to parties: WhatsApp and Firebase
14. Security and settings
15. Office Desk (closed records)
16. For the platform (SaaS) owner

---

## 1. Signing in, devices and the top bar

**Signing in.** You need a username and password and, for most roles, a code from your authenticator app or
a passkey. If the mill uses trusted devices, a new phone or computer must be approved before it can sign
in. Your administrator sees the request under Approvals.

**My devices** (user menu, top right → *My devices*). Lists every phone, computer and installed app you sign
in from, with its registration id (DEV-1A2B-3C4D), its last activity and whether it is approved. Remove a
device you no longer use: it is signed out and cannot sign in again. The mill may limit how many devices
each person uses.

**Installing as an app.** On a phone, open the site in Chrome or Safari and choose *Add to home screen*. It
then opens like an app.

**Top-bar search** (the search box, or **Ctrl + K** / **Cmd + K**):

- **Partial match.** Type part of a name or number: a token number (12 or 000012), a vehicle number with or
  without dashes (MNA-21-1234), a party, a sauda, a bill or invoice, a voucher, an employee, or a code
  (PTY-, VEH-, CTR-, CRL-, CBL- …).
- **Grouped results.** Results are grouped by kind of record; move through them with the arrow keys and
  press Enter.
- **All results.** *All results* beside the box opens every match on its own page.
- **Limits.** You only find records of your working mill, and only kinds of record you may open.

**Paper selector** (printer icon in the top bar). Chooses the paper this computer prints on. See section 12.

---

## 2. Masters and codes

Masters → **Parties**, **Vehicles**, **Drivers**, **Products**, **Paddy varieties**, **Godowns**, **Brands**,
**Tax rates**.

**Automatic codes.** Every master record gets a permanent code when saved without one; the code stays the
same when the record is renamed.

| Record | Code |
|---|---|
| Party | PTY-ALI-0001 |
| Product | PRD-RICE-0001 |
| Paddy variety | PV-1121-0001 |
| Vehicle | VEH-000001 |
| Labour contractor | CTR-ALI-0001 |
| Contractor rate | CRL-000001 |
| Contractor bill | CBL-000001 |

**Party roles** ("Acts as"): customer, vendor, broker/agent, farmer, transporter, **labour contractor**. A
labour contractor is also a vendor (it is paid) and gets its CTR code.

**Code Directory** (Masters → Code Directory) lists every code in one place, with Excel and PDF export for
people with the export right.

Details: `phase2-core-workflow.md`.

---

## 3. Gate: tokens, weighing, lab, unloading

Gate → **Gate entries** → *New entry*.

1. **Vehicle.** Type the registration number (registered vehicles are suggested), or tick *No number
   plate* for a bullock cart, donkey cart, bicycle or rehri, and describe it.
2. **Contractor token (optional).** Choose the *Labour contractor*, then the *Contractor vehicle*. The
   vehicle number and kind fill in, and today's rate is shown, e.g. "Rate CRL-000001: Rs 12.00 per maund
   (40 kg) of the final weight". If it says there is no rate, set one before billing (section 9).
3. **Jack type.** *With Jack* or *Without Jack*, printed on the token.
4. **Party.** Choose the sauda, or type the party name. Registered parties are suggested; a typed name
   makes no new party record.
5. **Save.** The token number is one continuous series for the mill (000001, 000002 …), never reused, the
   same for every kind of vehicle and for contractor tokens. Print the token slip from the message.

**Weighing.**

- The **Live weight** page (Gate → Live weight) takes the loaded and empty weights from the weighbridge
  (stable reading, with evidence).
- A Supervisor or above can type one weight by hand, with a reason; this is recorded.

**Lab.** For paddy, the lab test comes first. Within the limits it is accepted; outside them it goes to
negotiation (section 4) or is rejected.

**Unloading** (*Mark unloaded* on the vehicle page):

- **Godown and contractor.** Choose the godown and, if a labour contractor unloaded it, the contractor.
- **Rejected paddy.** Enter it **by weight (kg)** or by bags, with the reason. Say whether it stays **on
  the vehicle** (the empty weight then carries it, and the net is only what was unloaded) or is **taken off
  and kept aside** (its weight is subtracted from the net).
- **Correcting the vehicle number.** *Correct vehicle number* after weighing keeps the weights. After the
  slip, only the Owner or Super Admin can correct it.

**Weighment slip and gate pass.**

- *Issue weighment slip* freezes the weights and drafts the purchase bill.
- **Print slip** opens the print dialog; *Download slip (PDF)* saves the file.
- For dispatches, the gate pass works the same way.

Details: `printing-weighing-tokens.md`, `lab-first-procurement.md`, `contractor-vehicles-and-unloading.md`.

---

## 4. Procurement: saudas and negotiation

Procurement → **Saudas**: the purchase deal with a party (product, variety, quantity, rate per maund).

The order of a paddy vehicle is: lab test → price → weights → slip.

- **Lab result outside the grace limits.** The **Negotiation** page (Gate → Negotiation) shows the price
  versions and the Caat (weight cut).
- **Settlement.** A settlement is proposed by one person and approved by a second (main users may approve
  their own).
- **Rejected lots.** A rejected lot can be held at the mill (quarantine) or returned.

**Deduction rules** (Gate → Deduction rules): moisture, foreign matter, bag weight. Each rule has a fixed
weight, a percent of net or a rate cut, by product and variety, from a date.

Details: `lab-first-procurement.md`.

---

## 5. Stock and production

- **Paddy stock entry** (Stock & production → Paddy stock entry): paddy received without the gate (opening
  stock, transfers), held in a godown.
- **Requisitions and stock counts** go through approval; stock never goes negative.
- **Production batches** issue paddy to the mill line and record **several outputs**: head rice, broken,
  bran, husk and others. Yield and loss are checked against the outturn standards.

---

## 6. Sales and dispatch

Sales → **Sales saudas** → **Dispatch orders** → the vehicle at the gate → **Sales invoice** → **Gate pass**.

- **Credit control.** The buyer's credit limit is checked; an override needs approval.
- **Printing the invoice.** **Print invoice** opens the print dialog; *Download invoice (PDF)* saves it.

---

## 7. Accounts: bills, payments, books, roznamcha, bank e-mail

- **Purchase bills.** Drafted from the weighment slip; *Post to books*. **Print purchase bill** opens the
  print dialog.
- **Payments.** Requested, then released with approval; main users release their own. **Print payment
  voucher** opens the print dialog.
- **Expenses.** Paid by cash, bank or on credit to a party, with receipts attached.
- **Vouchers.** Journal, contra (cash ↔ bank), debit and credit notes. Period locks stop changes to closed
  months.
- **Cash book and bank book.** Day by day, with the daily closing.

**Roznamcha** (Accounts → Roznamcha → *Add a page*). Choose how the page comes in:

- **Mobile camera.** A QR link opens the phone camera for 30 minutes, for one photo.
- **Scanner.** The mill's network scanner reads the page.
- **PDF or image.** Upload the file.
- **Manual.** Type the entries.

The text is read (OCR) and the lines are matched to parties. Check each line before posting.

**Bank e-mail.** Accounts → *Bank e-mail* connects the mill's mailbox. Bank alerts are read every 5 minutes
into *Bank e-mail transactions*, matched to parties and confirmed by you.

Details: `phase3-accounting-automation.md`, `integrations-and-roznamcha-inputs.md`, `cashbook-bankbook-attachments.md`.

---

## 8. Party statement and sharing

Reports → **Party statement**. It also opens from *Statement* on a contractor bill.

**Choosing the statement:**

- **Party and period.** The opening and closing balances come from the party's ledger.
- **Filters:** transaction type (purchases, sales, payments, receipts, journals, notes), product, vehicle
  number, sauda, payment status (unpaid, partly paid, paid).
- **Views:**
  - **Detailed:** account entries with the running balance, the vehicles (token, vehicle, gross, tare, net
    and payable weight, rate, amount, bill, open amount) and the bills.
  - **Summary:** trips, net weight, billed, open, paid, received.
  - **Vehicle-wise** and **sauda-wise:** totals per vehicle or per sauda.
  - **Payments / receipts.**
  - **Contractor:** for a labour contractor, its tokens with billable weight, rate and charge, its bills
    with paid and balance, and its payments.

**Buttons at the top:**

- **Print** opens the print dialog. **Download PDF** saves the file.
- **WhatsApp** sends a **secure link** to the PDF, which expires in 3 days.
  - With the mill's WhatsApp connection, it is sent and shown as *handed to WhatsApp*.
  - Without it, WhatsApp opens with the message ready for you to press send.
- **E-mail** sends the PDF attached; you can edit the subject and message.
- **Secure link** makes a link to copy and send yourself.

**Sharing history** (at the bottom) lists every print, download, e-mail, WhatsApp message and link with what
really happened. The system never claims a message was delivered or read; WhatsApp and the mail server do
not tell it that.

People with the ledger right see every party; payroll staff see labour contractors only.

---

## 9. Labour contractors

Contractors are paid by **weight**, per **contractor vehicle**.

1. **Make the contractor.** In Masters → Parties, tick *Labour contractor*. A vendor chosen on any
   contractor screen also becomes one.
2. **Its vehicles.** HR → Contractor rates → **Contractor vehicles**: add a registered vehicle or a new
   number. A vehicle works for one contractor at a time; only the Owner or Super Admin can take it off.
3. **Its rates.** HR → Contractor rates → **Add a rate**.
   - **Who and what:** contractor, vehicle (only that contractor's vehicles, or "+" to add one), and the
     work: unloading, loading or transport.
   - **Basis:** per maund (40 kg), per kg, or **per custom weight** (e.g. Rs 50 per 100 kg; quantity and
     unit).
   - **Rate (Rs), effective from, effective to (empty = until replaced), status and remarks.**
   - **Charge** = billable weight ÷ rate weight × rate. The billable weight is the final net weight.
   - **Versions.** Two active rates for the same contractor, vehicle and work never overlap. A new rate
     closes the open one the day before.
   - **Rates already on a bill.** A rate on a bill cannot change its amount; add a new version from the new
     date.
   - **Older tokens.** A token is charged at the rate **in force on the day it arrived**. To bill older
     tokens, set the rate's *Effective from* on or before their days.
4. **Tokens.** Issue contractor tokens at the gate (section 3, step 2), or link many vehicles at once from
   the gate list (*Assign labour contractor*). The vehicle page shows the charge once the weight is final.
5. **Bills.** HR → Contractor bills → **Prepare bill**, basis *Vehicles*.
   - **Period.** The period may run up to **today**.
   - **Preview.** *Vehicles to bill* shows the tokens, billable kg and amount, and warns about tokens with
     **NO RATE on their day**.
   - **Tokens with no rate.** They are left out and listed so you can bill them later. If no token has a
     rate, a red message says why the bill was not prepared.
   - **Tokens still waiting for their final weight** are billed later.
   - **Deductions** (shortage, damage, advance recovered) need a reason.
   - **Approval.** A second person approves; this posts the labour cost and the amount owed to the
     contractor.
   - **Line snapshot.** Each line keeps the token, the weights, the rate and its basis.
   - **No double billing.** A token is never billed twice; a refused bill frees its tokens.
6. **Payments.** **Pay** on an approved bill pays all or part of it, from cash or a bank account, with a
   reference. Paying more than is still due is refused. The list shows paid, balance and Unpaid / Partly
   paid / Paid.
7. **Workers.** *Assign workers* (contractor bills list) links daily and monthly workers to the contractor
   for man-day and piece-work bills. A worker of one contractor cannot be added to another.

Details: `contractors-statements-search-printing.md`, `contractor-vehicles-and-unloading.md`.

---

## 10. HR, attendance, payroll and daily wages

- **Employees**, departments, designations, shifts, sites, holidays, leave types and leave requests.
- **Attendance.** By phone, with the location and a photo: punches at the mill site within working hours,
  from an approved phone. Anything else is flagged, never silently accepted.
- **Payroll runs.** Prepared, checked and finalised; payslips and the register.
  - **Printing:** choose *Output → Print now* for the print dialog, or *Download the file*.
- **Daily wages** (menu Daily wages): casual labour by group and category, a day's sheet, the wage slip.
- **Advances, reimbursements (staff claims), allowances, adjustments.**

Details: `hr-phase-*-summary.md`, `daily-wages-summary.md`.

---

## 11. Reports

Reports → **Books & reports** (trial balance, ledger statement, day book, ageing, stock value, cash and bank
book), **Business report**, **Financial reports**, **Operational reports**, **Statutory reports**, **Sales tax
returns**, **Vehicle register**, **All reports**, **Party statement**.

- **Viewing.** Everyone with the report right sees the reports on screen.
- **Downloading.** Downloading (Excel, CSV, PDF) needs the **export** right. A downloaded report carries a
  faint watermark with who printed it, when the mill's screen protection is on.
- **Visibility window.** The customer's data visibility window may hide old periods. Opening balances still
  include them.

---

## 12. Printing and paper

**Print opens the print dialog straight away; nothing is downloaded.**

- **Where it works:** the Print button on every list and record screen, and Print on slips, gate passes,
  invoices, bills, vouchers, records, HR documents and statements.
- **On a computer** the document loads in the background and the browser's print dialog opens.
- **On a phone** it opens in a new tab: use the share or print menu there.
- **Downloading.** **Download PDF** buttons are separate, for when you want the file.

**Paper sizes:** A4, A5, A6, Letter, small roll 80 mm, 58 mm or 50 mm, and a custom size. Everything prints
portrait, at actual size; wide reports shrink to fit.

**Which paper is used**, in this order:

1. a `?paper=` on the print link;
2. the mill's paper for that kind of document: **Branding → Paper per document**, for gate tokens, slips
   and gate passes, invoices and bills, vouchers and receipts, party statements, reports and other records,
   plus the custom width and height;
3. the paper chosen on this computer (top bar);
4. the document's own size.

**Branding** (Owner / Super Admin) sets the mill's name on documents, Urdu name, logo and emblem, colours,
invoice terms, slip note, footer and the bank account printed on sales invoices.

Details: `print-branding.md`, `reports-printing.md`.

---

## 13. Messages to parties: WhatsApp and Firebase

- **Connecting.** Connect the mill's WhatsApp Business account and Firebase under **Integrations**. The
  keys are kept encrypted and never shown again.
- **Which messages.** Each kind of message can be switched on or off: login details, money entries with the
  balance, vehicle unloaded, weights final.
- **Without a gateway.** Messages wait in *Party messages* for sending by hand.
- **Party accounts.** A party can sign in to see only its own vehicles, entries, bills and balance.

Details: `party-accounts-and-messages.md`, `integrations-and-roznamcha-inputs.md`.

---

## 14. Security and settings

**Security → Sign-in rules** (Owner / Super Admin):

- **New phones and computers:** must everyone's new device be approved, only selected accounts', or no one's?
- **Only inside the mill:** sign-in only within the mill's sites.
- **Devices per person:** how many phones and computers each person may use.
- **Record location with entries:** gate entries, stock entries, expenses and roznamcha pages keep where
  they were made.
- **Closed records leave the working screens after N months** (Owner only, for all the Owner's mills).
  Those records then go to the Office Coordinator's Office Desk.

Also under Security: Security centre, screen protection, sign-in attempts, sessions, access review, duress
PIN, two-factor and passkeys (user menu).

**Approvals** (top menu) lists everything waiting for a second person, with Approve or Reject and the reason
when a rule blocks you.

---

## 15. Office Desk (closed records)

Closed records older than the retention period (set by the Owner, section 14) move off the working screens.
The **Office Desk** is where they can still be read in full and restored with a reason.

- **Who uses it.** It belongs to the **Office Coordinator** role only. The Owner and the mill's Super Admin
  no longer have it in their menu; this keeps the working screens and menus free of old records.
- **What it can do.** Nothing on the Office Desk can be printed or downloaded. Every restore is recorded.
- **Panic button.** The Office Coordinator has a panic button for a lost phone: it locks the account
  everywhere and wipes the app on that phone.

Details: `archive.md`.

---

## 16. For the platform (SaaS) owner

Platform menu:

- **Customers, plans and invoices.** Customers and their mills, plans (modules and limits), invoices and
  payment methods.
- **Integrations and bank e-mail.** Integrations, the e-mail integration, and the bank e-mail templates
  used to read bank alerts.
- **Data visibility.** How many months of data each plan sees.
- **Separation.** Each customer's data is kept apart by the database itself; a customer never sees another's
  records.

Details: `saas.md`, `millnexa-branding.md`.
