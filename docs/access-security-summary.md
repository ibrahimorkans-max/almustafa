# Access security add-on — Summary (branch `access-security`)

## Bugs: what was failing and why

| Reported | Cause found | Fix |
|---|---|---|
| No Approve / Reject on the approval page | The buttons existed but were hidden whenever the current user could not decide. The only pending request on the dev database was raised by `superadmin` (maker-checker forbids self-approval), and every other demo account still had a password change or 2FA enrolment pending, which leaves an account with no permissions | Buttons always shown on pending requests (page and list rows), disabled with the reason when a rule blocks; note on approve, required reason on reject; requester notified; history section; one `decisionBlocker()` used by both the screen and the enforced check |
| No "Create Batch" button | The button was labelled "Open batch"; for the Super Admin in "All entities" mode it crashed on save (log: `MissingTenantContext`, 2026-10-08 11:21 UTC); the form lacked plan, supervisor and paddy issue | "Create batch"; planned quantity, supervisor, mill line, start time; paddy issued to production through the stock ledger in the same transaction, refused (with the available quantity) when the godown holds less; option to open empty for a stores requisition |
| Some actions forbidden for the Super Admin | No permission was missing: `Gate::before` answers every catalogued permission from the role and `access:sync` keeps the Super Admin role complete (all except the two "own records only" narrowing permissions). The failures were writes in "All entities" mode — *Open batch* and *Create price list* — where no entity is selected but every record belongs to one | Create screens redirect to *Switch entity* with "Choose the working entity first"; any write needing an entity shows the same message instead of an error page. Intentional rules (self-approval, locked periods, finalised records) still apply and say so |

## Built
* **Suspend / revoke** (Users screen): sessions and tokens end at once on every device; "Access disabled, contact your administrator" only after the right password; attempts audited with IP, device and time; the API tells devices `access_disabled`.
* **Emergency lockdown** (Security centre, Super Admin + 2FA code): system or one entity; others signed out and blocked ("System locked by administrator", HTTP 423 at sign-in, 401 `system_locked` for devices); scheduled work for the locked scope skipped, job middleware for queued work; gate agent shows SYSTEM LOCKED and keeps its queue; owners notified on lock and unlock.
* **Duress PIN** (user menu, Owner / Accountant / Super Admin): neutral "Signed out. Please try again later."; alert with time / IP / device to owners, Super Admins and security contacts; account flagged, optionally suspended and the device wiped; the administrator clears the flag after re-verification.
* **Stolen devices**: *Mark lost / stolen* queues the wipe and optionally signs the user out elsewhere; progressive delay (3rd failure on), 15-minute lock (5th), administrator unlock after 3 lockouts in 24 hours; owners alerted.
* **Maintenance mode** (Security centre): scheduled or immediate window, honest 503 page / JSON with Retry-After, secret bypass link (stored as a hash), health and `/verify` stay up, PWA and agent keep queueing and show the server's message.
* **Role-limited access**: explaining 403 page; **Access review** report with audited CSV.

## Differences from the request (by design)
* The app has its own permission catalogue and API tokens (no spatie / Sanctum); `approval.approve` is the existing `approval.decide`.
* No WebSocket server exists: force-logout is server-side revocation; devices learn the reason at their next request or sync.
* SMS goes to a log channel until a provider is chosen; e-mail and in-app notifications are live.
* Re-authentication uses the TOTP code (no hardware keys / WebAuthn yet).
* Stolen devices: the lost device keeps a token only to receive its wipe (every other call is refused); it is revoked once the wipe is acknowledged.

## Tested
See the end of this file for the run results. New tests: approvals screen (disabled with reason, approve with note, reject needs reason, list row action, notifications, history, immutability, Super Admin self-approval); create batch (stock issue, insufficient stock refused with nothing created, gap-free numbers, requisition route, role access); Super Admin probe over every screen in both modes; suspension / revocation (web + API cut, honest message, audit, rules, screen); lockdown (2FA, scopes, sign-in / web / API, notifications, unlock, job and scheduler pause, Security centre); duress (PIN rules, neutral sign-out, alerts, suspension, device wipe, API, flag clearing); stolen device (wipe-only access, sign-out elsewhere, progressive delay, admin unlock); maintenance (503 + Retry-After, honest page, bypass, schedule / end, Security centre); every role's forbidden screens show the 403 explanation; access review. Agent: lockdown keeps the queue and the barrier closed. PWA: maintenance and lockdown keep the outbox and show the server message.

### Results
* **PostgreSQL:** 475 passed, 1 skipped (includes the real backup / restore drill). **MariaDB:** 464 passed, 12 skipped (PostgreSQL-only tests).
* Agent: 33 tests. PWA: 15 tests. Larastan level 6, Pint and the secret scan are clean.
* Found during the full run: `ricemill:preflight` flagged the new `system_locks` table for having no row-level security. That is
  intentional: a whole-system lock must be visible to every entity's requests, and a firm-scoped policy would hide it. It is now
  an explicit, documented exemption, so preflight (and therefore deploys) pass.

## Next
* Choose an SMS provider for security alerts.
* WebAuthn security keys for Super Admin re-authentication.
* Show the PWA's `serverMessage` prominently on its status bar (the data is there).
