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

**Chat button** (round button at the bottom right of every screen). Opens the Assistant in a small window, so
you can ask or give an instruction without leaving the screen; *Full screen* opens the Assistant page. Both
have three tabs:

- **Chat.** Today's chat. It opens with "Hi there, how can I help you today?" and **every feature as a button**
  under it: one that adds a record gives its blanks to fill (*Add new party*, *Lab test*…), one that asks
  shows the answer at once (*Vehicles inside*, *Stock*…), and one that changes something puts an example in
  the box to finish. It carries on from screen to screen; *New chat* starts another one.
- **Lab test** (gate operator, lab technician, Lab Supervisor): the vehicles waiting for the lab are listed, then
  a form with one line per lab test of the mill (*Moisture / نمی (%)*, *Broken / ٹوٹا ہوا دانہ (%)*…) and the
  *Decision* (accept / reject / later). Before Confirm each result shows ✓ within standard, ⚠ within grace or
  ✗ above grace, with the verdict. Above grace the lot is rejected automatically. Only someone who may decide
  can accept or reject; otherwise it waits for the Lab Supervisor. Typed: `lab MNA-21-1234 moisture 14 broken 3`.
- **Features.** Everything the Assistant can do for you, grouped (Add records quickly, Gate and weighing,
  Business and stock, Approvals, Any screen…). Each feature says what it does, with examples: press one to put
  it in the box. *Fill the blanks* starts its quick button; **Confirm** marks a feature that changes data. At
  the end: every screen you may open.
- **History.** Your chats day by day (Today, Yesterday, then the date) for the last 90 days, with the first
  question, the time and the number of messages. Open one to read it and **carry on** with it; it stays under
  its own day. Only you see your chats. A change shown on an earlier day cannot be confirmed later: ask again.

- **Quick buttons** under the conversation: *Add new party*, *Vehicle arrived*, *New sauda*, *Add expense*,
  *Pay a party*, *Daily wages* (only those your role allows).
- Pressing one first shows **what is needed before you start**: ✓ ready (e.g. "12 sellers ready"),
  ✗ missing, with a link to add it, and • what to have at hand. If anything is missing, add it first.
- Then a short message with **blanks** (`____`) appears in the box, the first blank selected. Type over it,
  press **Tab** for the next blank, and press **Send** (or Ctrl+Enter). Leave *(optional)* blanks empty if
  not known; numbers may be typed in Urdu digits.
- **Choices for each blank.** Above the box, the data that fits the line the cursor is on shows as buttons: the
  vehicles waiting for the lab (by token), vehicle numbers, party names, with jack / without jack, paddy
  varieties, open saudas (with the seller), sellers, paddy, expense names, payment modes, kinds of work, labour
  groups, accept / reject / later. Press one and it is written in the blank, and the next blank is selected.
  Typing part of a name narrows the list.
- **Vehicle arrived** asks for the vehicle number, the party name, the type (with jack / without jack, printed
  on the token), the item (paddy variety), and optionally the sauda and the bags. A party name that is not a
  registered party is kept as typed, as on the gate's own form.
- The Assistant shows what it will do. Nothing is saved until you press **Confirm**. It works without an AI
  key too.

**Before a new record.** The "new" form of a party, sauda, sales sauda, dispatch order, vehicle at the gate,
payment, expense, daily wages, advance, requisition, production batch, employee and roznamcha page shows a box
at the top: what must exist first (with a link to add it) and what to have at hand.

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
  (stable reading, with evidence). The same board is on top of Vehicles at gate.
- The dial updates twice a second by itself; the page never reloads. The vehicle number, party and item
  boxes are sent only when you leave each box, so nothing you type is interrupted.
- **Live weight on every screen.** The weighbridge's weight shows in the top bar of every screen (code,
  kg, and a lamp: green *stable*, amber *moving*, grey *no signal*), for whoever may see the Live weight page.
  It updates by itself in the browser and never redraws the page or a form being filled in. Press it to
  open the Live weight page.
- **3D weighbridge** (below the weighing form on Live weight): a 3D view of the mill, its gates and the
  weighbridge, with cameras (aerial, main gate, weighbridge side, top view, follow vehicle, operator display)
  and night mode. It runs in the browser only, so the weighing form is never interrupted.
  - **LIVE**: the platform's display shows this weighbridge's own reading (the same as the dial), STABLE /
    MOVING / NO SIGNAL, and the vehicle number typed in the form; a vehicle stands on the deck while there is
    weight on it. Gates, cameras and queues are not connected to the system, so they are not shown.
  - **SIMULATION** (for training and demonstrations, marked as such everywhere): tractor trolleys, cargo trucks
    and container trailers come to the main gate, are identified and let in, queue for the weighbridge (first
    come, first served), are weighed only when stopped with every axle on the deck, unload paddy (or load
    rice into the container), come back for the second weight (net = gross − tare) and leave through the exit
    gate. A gate never closes on a vehicle. Buttons: Start, Pause, Reset, Demo tour, add a tractor trolley /
    container / truck, open or close each gate (refused while a vehicle is in it), Auto / Manual, and Generate
    weight slip. Its weights come from each model vehicle's empty weight and load; they are never recorded.
- **Waiting (token order)** lists every vehicle still waiting for a weight, by token number, including
  paddy lots whose lab test is still to add. It refreshes every 10 seconds and after each weight, so a
  vehicle registered at the gate appears on its own. Tap one to fill in the form.
- **Manual weighing** (Gate → Manual weighing; the **Owner** only) decides who may type a weight by hand,
  with checkboxes:
  - *Allow weights to be typed by hand* (off: every weight must come from the weighbridge);
  - *Allow both weights of a vehicle to be typed* (for when the weighbridge is out of order; off: only one of
    the two, the other from the weighbridge);
  - *Who may type weights*: tick the people. Nobody ticked: those whose role allows it (Supervisor, Super
    Admin, Owner).
  A typed weight always needs its reason (and the other weighbridge's slip when there is one), counts at once,
  is marked "typed by hand" everywhere and goes to the weight checks. The button on Live weight is *Enter one
  weight manually* (or *Enter a weight manually* when both are allowed), shown only to the people allowed.

**Weighing policy** (Gate → Lab tests to add → *Weighing policy*, Owner / Super Admin). The default is
**weigh first**:

1. a paddy vehicle is weighed loaded as soon as it arrives, with no lab restriction;
2. it then waits on **Gate → Lab tests to add** (the number beside the menu shows how many), where each
   vehicle opens **its own page** to add the lab test: its token, vehicle, party, item, bags and loaded
   weight at the top, then the results, the verdict and the decision;
3. it is unloaded, and weighed empty, only after the lab accepts it, so rejected paddy never goes into a
   godown. A rejected lot that was weighed is weighed empty and leaves.

A mill can choose **lab first** instead: the lab accepts each lot before it is weighed.

**Lab.** Within the limits a lot is accepted; outside them it goes to negotiation (section 4) or is rejected.

- **Lab test button** (top of Vehicles at gate and Live weight), for anyone with the lab right:
  1. choose the vehicle (only paddy lots waiting for the lab are listed, by token);
  2. type each result (each box shows its "OK up to" and "grace up to" limits);
  3. *Result by the rules* shows at once ✓ within standard, ⚠ within grace, ✗ above grace
     (= rejected automatically);
  4. the Lab Supervisor can choose *Accept*, *Reject* (with the reason) or leave it; a Lab Technician or
     the **Weighbridge / Gate Operator** only saves the result and the Lab Supervisor decides;
  5. *Save lab result*. An accepted lot appears in the waiting list ready for its next weight.
- The same can still be done on the vehicle page (*Record lab test*, *Lab: accept / reject*).

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
- For dispatches, the gate pass works the same way (it also shows the weight per bag).

**Gate passes** (Gate & weighbridge → Gate passes): for anything that leaves on a vehicle.

- *New gate pass*: choose **Sale** or **Other**, optionally a vehicle at the gate (fills in the details), the
  **party** (registered, or type the name), **vehicle number**, **number of bags** and **weight per bag**. The
  total weight is worked out as you type.
- *Issue and print* gives it the next **serial number** (e.g. AMR/GP/2026-27/0001) and a **verification code**
  with a QR code. Scanning it (or entering the serial and code at /verify) shows the party, vehicle, bags and
  weight, and whether the pass was cancelled.
- A pass is never changed. A supervisor can *Cancel* it with the reason; then issue a new one.
- A *Sale* pass prints with the **logo for selling**, any other with the mill's logo (section 12).

Details: `printing-weighing-tokens.md`, `lab-first-procurement.md`, `contractor-vehicles-and-unloading.md`.

---

## 4. Procurement: saudas and negotiation

Procurement → **Saudas**: the purchase deal with a party (product, variety, quantity, rate per maund).

The order of a paddy vehicle is: loaded weight → lab test → price → unloading and empty weight → slip (with
*lab first*: lab test → price → weights → slip).

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

- **Reading the whole photo.** With an AI key saved (Administration → AI assistant), the page is read by the
  AI: the complete page, both the *jama* (received) and *banam* (paid) sides, handwriting and Urdu, up to 200
  entries, each with what it was for and any bill or slip number. Headings, totals and balances are left out.
  Without a key, the server's reader handles printed English (and printed Urdu when the server has the Urdu
  language data installed).
- **Urdu pages.** Names and notes stay in Urdu as written, and are shown right to left. Each Urdu name is
  matched to the mill's own party or account (e.g. حاجی اسلم to "Haji Aslam"), as the AI is given the mill's
  names. Urdu digits (۱۵,۰۰۰) and the words جمع (received) and بنام (paid) are understood. Amounts may also be
  typed in Urdu digits when correcting a line. Very long pages keep every entry read in full.
- Every entry is saved on the page. Each name is matched to its **party** (by name or code, or the only party
  whose name contains it) or to an **account** such as Diesel or Bijli. The message after saving says how many
  entries were read and how many still need their party or account (*Correct entries*).

**Daily budget** (Roznamcha → *Daily budget*; Owner, Super Admin or whoever approves payments): how much cash
may be paid out (banam) in a day.

- Set the **usual amount** for every day, and a day's **own amount** when needed (e.g. a payments day).
- The roznamcha list shows today's budget, what is paid out and what is left (red when over). Each page shows
  its day the same way, and the list has a *Day's budget* column.
- A page that takes its day **over the budget** is posted only with the **reason**, which stays on the page.

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
really happened. A WhatsApp message shows *Delivered* or *Read* once WhatsApp reports it through the webhook
(section 13); until then, and for e-mail, the system never claims it was delivered or read.

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

- **Logo for selling**: a second logo for sales invoices, gate passes for sales, dispatch orders and sale
  saudas. Every other document uses the mill's logo. Left empty, the mill's logo is used everywhere.

Details: `print-branding.md`, `reports-printing.md`.

---

## 13. Messages to parties: WhatsApp and Firebase

- **Connecting.** Connect the mill's WhatsApp Business account and Firebase under **Integrations**. The
  keys are kept encrypted and never shown again.
- **Which messages.** Each kind of message can be switched on or off: login details, money entries with the
  balance, vehicle unloaded, weights final.
- **Without a gateway.** Messages wait in *Party messages* for sending by hand.
- **WhatsApp webhook** (Administration → WhatsApp webhook; Platform → WhatsApp webhook for the product's number).
  It shows the **Callback URL** and **Verify token** to copy into Meta → WhatsApp → Configuration → *Configure
  Webhooks*, with the steps:
  1. paste both and press *Verify and save* (the address must be the system's public **https** address);
  2. under *Webhook fields* subscribe to **messages**;
  3. press **Save app secret** and paste the app secret (App settings → Basic). Until it is saved, everything
     WhatsApp sends is refused, because it is how the system knows a call really comes from Meta;
  4. publish the Meta app.
  Then *Party messages* shows **Delivered / Read / Not delivered** for each WhatsApp message, and the page lists
  the receipts and the **messages parties send** to the business number (matched to the party by its mobile).
  *New verify token* makes a new one (type it in Meta again).
- **Party accounts.** A party can sign in to see only its own vehicles, entries, bills and balance.
- **Send on WhatsApp (files).** While WhatsApp is connected, weighment slips, gate passes, sales invoices,
  purchase bills, payment vouchers and party statements have *Send on WhatsApp*: the PDF goes as a file from
  the mill's number with a short message, which can be changed before sending.
  - The message comes from the mill's template: Integrations → **WhatsApp file message**. Words in braces are
    filled in: {party}, {document}, {number}, {date}, {mill}.
  - To reach anyone, approve a WhatsApp template whose **header is a Document** and whose body has one
    variable, and give its name as *Template for files* when connecting WhatsApp. Without it, WhatsApp
    delivers a file only to someone who wrote to the mill in the last 24 hours (the system says so).
  - Each file sent to a party shows in Chats with its delivery.
- **Chats** (Masters → Chats): every message sent to each party (WhatsApp or SMS, with Sent / Delivered /
  Read / To send by hand / Failed) and every WhatsApp message the party sent back, as one conversation per
  party, newest first. Search by party, code or number. Login details are never shown again.
- **AI assistant** (Administration → AI assistant; Platform → AI assistant for every mill): choose the **provider**:
  Anthropic (Claude), **OpenRouter** (one key for Claude, GPT, Gemini, Llama, DeepSeek and more), OpenAI, Google
  Gemini, Groq, DeepSeek, Mistral, or **Other** (any OpenAI-compatible service, by its https address). Pick a
  suggested model or type any model the provider offers, and paste its **API key**. The key is checked with the
  provider before it is saved, kept encrypted, and only its last four characters show. For reading roznamcha
  photos, choose a model that reads pictures.
  A mill's own key is used before the product's. *Test the connection* asks Anthropic once; *Remove the key*
  takes it away. The Assistant (and the AI reader of roznamcha pages, when chosen) uses it.

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
- **Payment page** (Customers → *Payment page*): who sees *My subscription* (plan, price, invoices, ways to
  pay): **every customer**, **none**, or **only the customers you tick**. Tick *Payment page* on a customer
  in the list, or choose customers and use *Show / Hide the payment page* (this switches to "selected" by
  itself). Where it is hidden, the customer gets no invoice or trial reminders; a blocked or read-only company
  is still told so.
- **Lifetime price.** Each plan can have a **lifetime price, paid once** (Plans → edit). The customer sees it
  on *My subscription* ("or Rs … once, for lifetime access"). *Give lifetime access* asks the **amount paid
  once** (the plan's lifetime price is filled in); the customer's page and the platform overview show it,
  and the overview's **Lifetime customers** card shows how many there are and the total paid once.
- **Several accounts at once** (Customers → *New customers (several)*): choose the plan and how they start
  (free trial with its days, or active), then one row per account: alias, company, phone, Owner's name,
  username and temporary password (filled in). Each gets its first mill and Owner sign-in; a row that fails
  (e.g. a username taken) is listed and the others are still opened.
- **Alias.** Your own name for an account (on *New customer*, the several-accounts form and *Edit*). It shows
  only on the platform screens (list, search, the customer's page); the customer never sees it.
- **Integrations and bank e-mail.** Integrations, the e-mail integration, and the bank e-mail templates
  used to read bank alerts.
- **Data visibility.** How many months of data each plan sees.
- **Separation.** Each customer's data is kept apart by the database itself; a customer never sees another's
  records.

Details: `saas.md`, `millnexa-branding.md`.

## 17. Weighing modes, lab setup, users and service costs

### The four weighing modes (switched on by the SaaS owner only)

| Mode | Steps |
|---|---|
| 1 — Lab test before the final slip | Entry → loaded weight → lab test → approval → final slip. The final slip waits until the lab is done. |
| 2 — Weight without a lab test | Entry → loaded weight → unloading → empty weight → slip. The slip shows only the vehicle and the weights. |
| 3 — Weight first, lab test later | Entry → loaded weight → **Weight-only slip** at once → lab test → final slip. Both slips are kept (vehicle page → **All slips given**). |
| 4 — Lab test first | Entry → sample → lab test → **accept**, **reject** or **negotiation** → weighbridge → final slip. |

- **Platform → Weighment modes**: tick the modes for each company (its defaults) and, where needed, give one mill its own setting (**Mill setting**) or send it back to its company's. Every change is listed under **Change history** with who, when and why.
- A mill sees the modes switched on for it under **Gate → Lab tests to add → Weighing modes**, and its Owner picks which one new paddy vehicles start in. It cannot switch modes on or off.
- When more than one mode is on, **Live weight** and **New vehicle** show a **Weighing mode** choice for paddy. A vehicle keeps the mode it was registered in, even if the mode is switched off later.
- Mode 4 negotiation: **Lab: negotiation** (lab supervisor) → **Negotiation approved** (the vehicle goes to the weighbridge) or **Negotiation failed** (rejected). A rejected or negotiating lot is weighed only through **Weigh without lab acceptance**, with a reason.

### Lab setup

- **Lab tests** (Gate → Lab tests): besides the standard and grace limits, each test has a minimum and maximum acceptable value, a tolerance, **Mandatory**, **needs a supervisor to accept in grace**, and **May change the payable weight**. The deduction % and how it is worked out stay under Deduction rules.
- **Lab time rule** (Gate → Lab tests to add): the minutes the lab has, counted from arrival or the loaded weight, until the result or the decision; a late result is allowed and flagged, allowed only by a supervisor with a reason, or refused. An alert goes to the lab supervisors when the time runs out.
- **Platform → Weighment modes → Lab features**: the SaaS owner allows each company or mill its own tests, quality deductions, time grace and negotiation.

### Users

- Each mill: **Administration → Users** (add, edit, change role, suspend, revoke, reset password, reset 2FA; last sign-in). Accounts are never deleted; revoking keeps their records. A Super Admin cannot change an Owner's account.
- SaaS owner: **Platform → All users** — every user of every company and mill: add a user to any mill, change role, suspend / revoke / reinstate, reset the password, and see their sign-ins and activity.
- New role: **Report Viewer** — sees screens and reports, changes nothing.

### API, SMS, WhatsApp and e-mail costs

- Every call to an outside service is recorded by itself: AI, WhatsApp, e-mail, push notifications, bank mailboxes, and any SMS, map or OCR service connected later. Each record has its usage ID, company, mill, person or automatic process, module, reason, time, quantity, cost and status. Records are never changed; a retry is not counted twice; delivery reports from WhatsApp are added beside them.
- **Platform → API & message costs**: totals, successful and failed, estimated cost, the cost providers report, the amount charged to companies, and what is not yet reconciled. Broken down by company (click one), mill, provider, kind of service, module and day; Excel and PDF.
- **Platform → Service prices & billing**: prices per provider and service from a date, for everyone or one company (free allowance, tiers, tax, monthly package fee, quota). Also: **Record a provider's bill**, the reconciliation of estimate against bill, and **Issue monthly statements** per company.
- Each mill: **Administration → Service usage** (Owner and Super Admins, or anyone given "usage.view"): its own company's usage only, with Today … Last month and custom dates, and Excel / PDF.
- No SMS gateway is connected yet; SMS usage appears once one is.

### Outside services: connected once, by the SaaS owner

- WhatsApp, AI, push notifications and the WhatsApp webhook are connected only under **Platform** (Integrations, AI assistant, WhatsApp webhook). No mill connects its own or sees any key; a mill sees **Administration → Connected services** (read only).
- **One WhatsApp number sends for every mill.** Each message carries the mill's own name — in bold at the start of the message, or in the template's header if the approved template has a header variable (Platform → Integrations → WhatsApp → "Each mill's name in its messages"). The name is the mill's letterhead name (Branding) unless the SaaS owner sets another under **Platform → Sender identities**, where a mill's WhatsApp can also be switched off.
- WhatsApp's own sender name (at the top of the chat) is the one Meta approved for the number; it is the same for every mill. The mill's name is in each message.
- E-mails to parties come from the mill's e-mail sender if one is set, otherwise from the platform's address under the mill's name.
- AI: one key for every mill, with an optional **fallback provider** used when the main one fails. Every call is recorded against the mill that made it (API & message costs).
