# Contractors by weight, party statements, top-bar search, direct printing

Covers the "complete master development prompt" items that were not already in the system. Everything
else in that prompt (gate, lab-first purchase, weighing, codes, bank e-mail, roznamcha, devices, PWA,
visibility windows, portrait printing) was already built and is described in the other documents here.

## 1. Labour contractors, paid by weight per vehicle

**Contractor parties.** A party can now be a *Labour contractor* (Masters → Parties → "Acts as"). It also
becomes a vendor (it is paid) and gets its own permanent code **CTR-ALI-0001** beside its PTY code. A vendor
chosen as contractor on any contractor screen becomes one automatically. Existing parties with contractor
rates, bills, workers or tokens were marked during the migration.

**Contractor vehicles.** HR → Contractor rates → *Contractor vehicles*: add a registered vehicle or a new
number to a contractor. A vehicle works for one contractor. Linking it to another one is refused, and only
the Owner or Super Admin can take it off. The same "+" is on the rate form's vehicle field.

**Rates (CRL-000001).** HR → Contractor rates. The form has the contractor, the vehicle (only that
contractor's vehicles), the work (unloading, loading or transport), the rate (Rs), the basis, the custom
weight (quantity and unit), effective from/to, status and remarks.

- Bases: **per maund (40 kg)**, **per kg**, or **per custom weight** (e.g. Rs 50 per 100 kg; units kg,
  maund or ton). Fixed amounts per vehicle or per trip are refused for new rates. Old per-vehicle, per-bag
  and per-ton rates stay readable and are still billed.
- **Charge = billable weight ÷ rate weight × rate**, rounded to the paisa. The billable weight is the final
  net weight, after any paddy taken back off the vehicle.
- **Versions:** two active rates for the same contractor, vehicle and work never cover the same day. A new
  rate closes the open older one the day before; any other overlap is refused, naming the rate it clashes
  with.
- A rate used on a bill keeps its amount and weight. To change it, add a new version.
- The rate for a token is the one effective on its arrival day. A rate for that vehicle is used first,
  then one for its kind of vehicle, then one for any vehicle.

**Contractor token at the gate.** Gate → New entry has *Labour contractor (contractor token)* and then
*Contractor vehicle*. Choosing the vehicle fills its number and kind and shows today's rate (or says there
is none). The token uses the same continuous numeric series as every other token, with Jack type as
usual. The vehicle page shows the charge once the weight is final, or the bill it went on.

**Bills (CBL-000001).** HR → Contractor bills → Prepare bill, basis *Vehicles*.

- The period may run up to **today**, since a token is billable as soon as its weight is final.
  Attendance and piece-work bills still need days that are over (this was why "bill generation did not
  work" for today's vehicles).
- Tokens still waiting for their final weight are left for a later bill, and the preview says how many.
- Each line keeps a snapshot: token, vehicle, gross, tare, net and billable kg, rate code, basis, basis
  weight and rate.
- Duplicate billing is impossible: a token is marked with its bill, and a refused bill frees it.
- **Deductions** (shortage, damage, advance recovered …) are entered with a reason. They reduce the
  amount payable and the labour cost posted.
- **Payments:** *Pay* on an approved bill, in full or in part, from a cash or bank ledger. Each payment
  posts its own voucher (Dr contractor, Cr cash or bank). Paying more than is due is refused. The bill
  shows paid, balance and Unpaid / Partly paid / Paid, with a filter for each.

## 2. Party statement (and contractor statement)

Reports → **Party statement** (also *Statement* on each contractor bill).

- **Filters:** party, period, transaction type (purchases, sales, payments, receipts, journals, notes),
  product, vehicle, sauda and payment status.
- **Views:** detailed, summary, vehicle-wise, sauda-wise, payments / receipts, and contractor (tokens with
  weights, rate and charge, its bills with paid and balance, its payments).
- **Balances come from the party's ledger.** The opening balance is everything before the period (clamped
  to the customer's visibility window), and each entry carries the running balance.
- **Tokens:** each one with gross, tare, net and payable weight, rate per maund, amount, its bill and what
  is still open on it.
- **Who can use it:** people with *ledger.view* see any party. Payroll (*hr.payroll.run*) sees labour
  contractors only.

**Sharing:**

- **Print** opens the print dialog. **Download PDF** saves the file.
- **E-mail** sends the PDF attached, with a subject and message you can edit.
- **WhatsApp** sends a **secure link** (signed, expires in 3 days, no figures in the address):
  - through the mill's WhatsApp Business connection when it is set up, recorded as *handed to WhatsApp
    (delivery not confirmed)*;
  - otherwise WhatsApp opens with the message for the person to send, recorded as *link prepared*.
- **Secure link** makes the link to copy by hand.
- **Sharing history** lists every print, download, e-mail, WhatsApp and link with what actually happened.
  The system never says a message was delivered.

## 3. Top-bar search

The search box at the top of every screen (**Ctrl + K** or **Cmd + K**) looks across parties, vehicles,
products, paddy varieties, godowns, drivers, gate entries, saudas (purchase and sales), dispatch orders,
sales invoices, purchase bills, payments, vouchers, expenses, ledgers, claims, contractor bills and rates,
requisitions, production batches, payroll runs and employees.

- A part of a name or number is enough.
- Results are grouped by kind of record and can be chosen with the arrow keys.
- A gate token is found by its number (12 or 000012).
- A vehicle number is found with or without dashes (MNA-21-1234).
- *All results* opens every match on one page (Search).
- Only the working mill's records show, and only the kinds of record the person may open.

## 4. Direct printing and paper per document

**Print opens the print dialog; nothing is downloaded.**

- The PDF loads in a hidden frame of the page and the browser's print dialog opens on it.
- On a phone it opens in a new tab, where the share/print menu prints it.
- This applies to:
  - the Print button on every list and record screen;
  - Print on record pages and table rows;
  - the new Print buttons beside "Download PDF" (weighment slip, gate pass, payment voucher, sales
    invoice, purchase bill);
  - HR documents (Output: *Print now* or *Download the file*);
  - the party statement.
- **Download PDF** remains a separate button.
- Only this site's own print frame may show these PDFs. Ordinary pages still refuse to be framed, and a
  waiting print can be opened only by the person who asked for it, for 10 minutes.

**Paper sizes:** A4, A5, A6, Letter, small roll 80 / 58 / 50 mm, and a **custom size**.

**Branding → Paper per document** sets the paper for each kind of document: gate tokens, slips and gate
passes, invoices and bills, vouchers and receipts, party statements, reports, other records. It also sets
the custom width and height in mm.

The paper is chosen in this order:

1. `?paper=` on a link;
2. the document type's paper;
3. the computer's own choice (top bar);
4. the document's own size.

## Migrations

- `2026_11_29_000100_contractor_weight_rates`:
  - parties: `is_contractor`, `contractor_code`;
  - vehicles: `contractor_party_id`;
  - contractor_rates: `code`, `vehicle_id`, `basis_qty`, `basis_unit`, `effective_to`;
  - contractor_bills: `deduction_paise`, `deduction_note`, `paid_paise`;
  - new table `contractor_bill_payments` (append-only, RLS).
- `2026_11_29_000200_statement_shares`: the sharing history (append-only, RLS).

## Tests

`tests/Feature/Fixes/ContractorWeightRatesStatementSearchPrintTest.php` covers:

- weight bases and codes;
- versions and overlaps;
- one contractor per vehicle;
- contractor tokens and billing today with the snapshot;
- deductions and partial payments with a settled ledger;
- statement PDF, WhatsApp, e-mail, secure link and the screen;
- search, including another mill's records;
- the print job owner and the frame rules;
- paper per document type;
- the gate form.

`ContractorVehiclesAndUnloadingTest` was moved to weight rates.
