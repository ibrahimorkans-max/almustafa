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
* Stock ledger (never negative), requisitions, stock counts with approval, production batches with yield and loss alerts.
* Sales: deals, dispatch orders, credit control with override approval, GST invoices, gate passes.

## Money and tax (phases 5, 7–8)
* Purchase bills from weighbridge slips, payments with second approval above ₹5 lakh, bank files and reconciliation, vouchers, period locks.
* Claims with credit / debit notes, price lists, confidential margins, archive.
* GST returns (GSTR-1, 3B), e-invoice / e-way bill through a provider seam, TDS 194Q, reports, dashboard, anomaly alerts, public `/verify` for printed documents.

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
