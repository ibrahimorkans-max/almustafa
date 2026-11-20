# Rice Mill ERP — Feature list

Every figure shown is the recorded figure. Confidential values (cost, margin, broker spread) are hidden from roles
without permission, never altered; there is no second set of books. Every change is in the append-only, hash-chained
audit log.

## Foundation (phases 1–2)
* Firms (entities) with database row-level security; Super Admin across firms with a chosen working entity.
* Roles from templates, permission catalogue, maker-checker for role changes; per-role 2FA and session limits.
* Web sign-in with 2FA and recovery codes; API tokens bound to the device; trusted devices for Super Admins; IP allow-list.
* Masters: parties, vehicles, drivers, products, brands, godowns, tax rates; offline PWA with conflict review.

## Operations (phases 3–4, 6)
* Procurement: requirements, quotes, deals (sauda) with approval; brokers see only their own deals.
* Gate and weighbridge: stable-weight evidence, repeat-tare check, scale certificates, lab tests, slips; offline gate agent with ANPR, boom barrier and display board (fails closed).
* Tokens (first come, first served) with a token slip; Live weight page with Loaded / Empty weight buttons and one weight typed by hand (Supervisor+, reason and slip); lab tests per product and variety, correctable with a reason; Negotiation page with the full price and Caat timeline; weight bill without amounts; every slip and report on A4, A6, Letter or an 80 mm roll (see `printing-weighing-tokens.md`).
* Small and unregistered vehicles at the gate: mini truck, loader rickshaw, rickshaw, motorbike, bicycle, bullock cart (bail gari), donkey cart, horse cart (tanga), hand cart (rehri). A vehicle with no number plate is entered with its kind and a description; it gets its own reference (NOPLATE + gate entry number) and the flag `no_number_plate`.
* Stock ledger (never negative), requisitions, stock counts with approval, production batches with yield and loss alerts.
* Sales: deals, dispatch orders, credit control with override approval, GST invoices, gate passes.

## Money and tax (phases 5, 7–8)
* Purchase bills from weighbridge slips, payments released with one approval (main users release their own), bank files and reconciliation, vouchers, period locks.
* Claims with credit / debit notes, price lists, confidential margins, archive.
* GST returns (GSTR-1, 3B), e-invoice / e-way bill through a provider seam, TDS 194Q, reports, dashboard, anomaly alerts, public `/verify` for printed documents.
* Party accounts: a supplier or buyer signs in to see only its own vehicles, account entries, bills and balance; messages to parties (login details, each money entry with the balance, vehicle unloaded, weights final) by WhatsApp or SMS, each kind switchable, with an outbox for sending by hand until a gateway is connected (see `party-accounts-and-messages.md`).
* Cash book and bank book with a daily closing, and supporting files on vehicles, bills, payments and approvals (see `cashbook-bankbook-attachments.md`).

## Operations safety (phase 9)
* Encrypted backups with off-site copy, weekly restore drill, audit-chain anchoring by e-mail.
* `/health`, scheduler and queue heartbeats, alerts for failed tasks, jobs and sign-in bursts.
* `ricemill:preflight`, VPS provisioning, zero-downtime deploys with rollback, secret scanning in CI, DPAPI-protected agent secrets.

## Access security (add-on)
| Feature | What it does |
|---|---|
| Approvals screen | Approve / Reject on the request page and in the list, with note / required reason; when the rules block you, the button is disabled and says why ("You cannot approve your own request", setup pending, missing permission, second approver needed); full history; the requester is notified; decided requests are immutable |
| Create batch | "Create batch" button; number given automatically; paddy, mill line, planned quantity, supervisor, start time; the paddy taken is issued to production through the stock ledger in the same step and refused when the godown holds less; or the batch opens empty for a stores requisition |
| Super Admin and firms | Every screen opens for the Super Admin in the working entity; in "All entities" mode, create screens and writes explain "Choose the working entity first" instead of failing |
| Suspend / revoke accounts | Suspend (reversible) or revoke (permanent) with a reason; every session and token ends at once (browser, phone, PWA, gate agent); after the right password the person sees "Access disabled, contact your administrator"; each attempt is logged with IP, device and time |
| Emergency lockdown | Super Admin, with the current 2FA code, locks the whole system or one entity: everyone else is signed out and blocked, scheduled work for the locked scope pauses, gate agents show SYSTEM LOCKED and keep their readings; owners are told on lock and unlock; unlock is a separate audited action |
| Duress PIN | Owner, Accountant and Super Admin may set a PIN; typed instead of the password it ends the sign-in with "Signed out. Please try again later.", alerts owners, Super Admins and security contacts with time, IP and device, flags the account and optionally suspends it and wipes the device in use |
| Stolen devices | "Mark lost / stolen": the device erases its local data at its next connection and can do nothing else; optionally the user is signed out everywhere else; owners are alerted. Sign-in slows down after 3 wrong passwords, locks for 15 minutes after 5, and after 3 lockouts in 24 hours needs an administrator to unlock. Offline apps lock after 72 hours without contact |
| Maintenance mode | Super Admin schedules or starts a window with a message and end time; others see an honest page or 503 + Retry-After; a secret bypass link keeps the Super Admin working; offline devices keep queueing |
| Role-limited access | Screens and records follow the role's permissions and the firm (brokers: own deals only); forbidden screens show a page that names the role and says whom to ask; Access review report (role, permissions, 2FA, last sign-in, dormant / never used / setup pending / locked / duress) with audited CSV |

## Bills on the spot, salaries, balances and sign-in checks (latest)
In the app, **Features guide** (top of the menu) shows every screen the person may open with its purpose, how it works and
the steps; the **Assistant** answers "how do I …" questions (English or Roman Urdu, e.g. "salary voucher kaise banate hain")
from the same guide (`app/Support/FeatureHowTo.php`), with a link to the screen.

| Feature | Purpose | How it works / how to do it |
|---|---|---|
| Quick bills (Sales & dispatch) | Any bill made on the spot | Choose Sale, Purchase (paddy), Rice for paddy (hisaab), Milling (mazdoori) or Weighing. Party or walk-in customer (pays in full); optional sauda (its quantity left goes down) or gate entry. Vehicle of any kind incl. bike and by hand, date and time, both weights for weighing. Many lines (product/variety): net = weight − empty/bardana − bags × deduction per bag − other deduction; amount = net ÷ 40 × rate per maund. Charges added or taken off (vehicle rent, labour, commission). Paid, part-paid or unpaid by cash, cheque, IBFT, Raast, RTGS, pay order, card or wallet. Posts to the books and the stock at once; "Receive payment" / "Pay the party" later; "Cancel this bill" reverses money, stock and sauda quantity. |
| Direct dispatch | Rice leaving by bike, by hand or in a small quantity | New dispatch order → "Direct": pick the sauda, products and prices fill in, type the weights, bags and deduction per bag, charges and payment. The credit limit is checked, the bill is made and the order is marked dispatched. |
| Salary on the employee form | Salary from the first day | Monthly or daily amount on "New employee" becomes the salary structure from the joining date. |
| Salary vouchers (HR) | Pay one employee on the spot | Employee, salary/bonus/other, month (or days worked for daily wage), advance instalment and other deduction, how and from which account. Posts the voucher, recovers the advance and marks the month paid so payroll skips it. |
| Party balances (Reports) | Who owes the mill, whom the mill owes | From the books, as on any date; filter receivable/payable; unpaid bills, last money in/out; link to the statement. |
| Employee balances (HR) | Salary paid and due | For a month: salary, paid (vouchers and payroll), still due, advance/loan balance. |
| Stock report (Stock & production) | Paddy and rice stock for any dates | Opening, in, out, closing per product (and per godown), kg and maund, from the stock ledger. |
| Milling weights | Paddy-to-rice weighing | On a production batch's input and output: weight on the scale − bags × bardana per bag − other deduction = net weight; the working is kept in the note. |
| Party receipts and advances | Money received from a party | Vouchers → New entry → "Receipt from party": against invoices or on account (advance). Payments out: Payments → New payment, against bills or as an advance. |
| Godown-to-godown | Move stock | Requisitions → "To another godown (transfer)". |
| Sign-in checks | Prove it is you without anyone's approval | First sign-in, new network/device and forgotten password: the e-mail has a 6-digit code (typed first) and a 2-digit number; the screen shows 4 numbers and moves on when the right one is tapped (a wrong tap cancels both codes). Then the person's own second check: passkey, authenticator app, recovery code or a code on the mobile app. Owner and Super Admin never need anyone's approval. |
| Two-factor per role | Make the authenticator compulsory | Roles → open a role → "Require 2FA for this role" (Owner / Super Admin): everyone in it sets up an authenticator at their next screen. "Stop requiring 2FA" needs a reason. Already on by default for Owner, Super Admin, Accountant, Purchase Manager, Finance Manager, HR Manager and Office Coordinator. Audited. |
| New phone, approved by its owner | No waiting for someone else | In the mobile app, a person with an authenticator types its code at sign-in; on a new phone that code also approves the phone, and any approval request it raised before is withdrawn. Without an authenticator the phone still waits for an approval. |
| A weight the vehicle already has | Weigh a vehicle only once here | When a vehicle brings a slip from another kanta (its empty or loaded weight), anyone on the staff (unless the Owner ticks particular people under Gate → Manual weighing) enters it, a reason optional, at any step, even at arrival: on the weighbridge screen "Enter one weight manually", or on the vehicle's page "Enter a weight it already has". If it is the next weight it counts at once; otherwise it is noted and recorded by itself when the vehicle reaches that step (e.g. the empty weight right after unloading). The other weight comes from the weighbridge (both only when the Owner allows it). Marked "typed by hand", with the reason and slip, and sent to the weight checks. |
