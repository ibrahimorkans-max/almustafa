# Access security add-on — Plan (branch `access-security`)

## Bugs: reproduced causes

| Bug | Reproduced on the dev database / logs | Cause |
|---|---|---|
| 1. No Approve / Reject on `/admin/approvals/{id}` | The one pending request (`device.trust`) was raised by `superadmin`; every other demo account still has a forced password change or 2FA enrolment pending and therefore holds **no** permissions | The buttons exist but are *hidden* whenever `canDecide()` is false. The maker-checker rule (nobody approves their own request) applied, and nobody else could decide yet. The page never said why |
| 2. No "Create Batch" button | The button exists on the Batches list, labelled **"Open batch"**; saving it as Super Admin in "All firms" mode crashed (`MissingTenantContext`, log 2026-10-08 11:21 UTC) | Wording, plus the crash below. The form also lacks the requested fields (planned quantity, supervisor, paddy issued at creation) |
| 3. Actions forbidden for the Super Admin | A probe visiting every admin page as Super Admin with a working firm: **no 403s**. Logs: *Open batch* and *Create price list* failed with "No entity is selected" | In "All firms" mode no firm is selected, but every new record must belong to one firm. Create pages and actions let the Super Admin start, then crashed on save. Permissions themselves are fine: `Gate::before` answers every catalogued permission from the role, and `access:sync` keeps the Super Admin role complete |

Also found: the `SUPERVISOR` role in both demo firms lacks `master.manage`, which its template now includes. That is correct behaviour (later template additions to existing roles go through maker-checker), so it is listed, not changed.

## Mapping the request onto this codebase
* There is no spatie/laravel-permission and no Sanctum: the app has its own permission catalogue, roles, `Gate::before`, opaque API tokens and auth sessions. These are reused; `approval.approve` is the existing `approval.decide`.
* There is no WebSocket server. "Force logout" = server-side revocation of sessions and tokens (the next request or sync gets 401 with a reason code) plus a `force_logout` / `locked` instruction in the sync response.
* There is no SMS provider yet. Alerts go by e-mail and in-app notification through one `SecurityNotifier`; an SMS channel slots in when a provider is chosen.
* There is no hardware-key (WebAuthn) support. Lockdown re-authentication uses the TOTP code.

## Fixes
1. Approvals: Approve / Reject always shown on pending requests (page and table row) to anyone with `approval.decide`; when the rule blocks the user, a disabled button with the reason ("You cannot approve your own request", "Needs a second approver", "Finish your account setup first"). A history section (audit entries for the request). The existing service already decides in a transaction, executes, audits and notifies.
2. Batch: "Create batch" button; form adds planned paddy (kg), supervisor, and paddy issued now (godown + kg). Creating opens the batch and issues the paddy to production through the stock ledger in one transaction; insufficient stock is refused with the available quantity.
3. Super Admin "All firms" mode: create pages and actions that need a firm explain "Choose the firm to work in first" with a link to *Switch firm*, instead of crashing. A probe test as Super Admin over every resource / page / create page; the intentional rules (self-approval, locked periods, finalised records) keep applying.

## Features (in order, tests after each)
1. **Suspend / revoke accounts**: statuses `suspended` (reversible) and `revoked` (permanent); sessions, tokens and the device's sync access are cut at once; after a correct password the sign-in says "Access disabled, contact your administrator"; every attempt is audited with IP, device and time.
2. **Emergency lockdown** (system or one firm): Super Admin only, confirmation + TOTP; revokes all sessions / tokens in scope except the Super Admin's; non-Super-Admin sign-in, web and API answer "System locked by administrator"; queued jobs for the locked scope are released for later; the gate agent shows LOCKED and keeps its local queue; owners notified on lock and unlock; unlock is a separate audited action.
3. **Duress PIN** for Owner, Accountant and Super Admin: set separately (hashed); entered in the password field at sign-in, it ends the attempt with "Signed out. Please try again later.", silently alerts the owner(s) and security contacts, flags the account and optionally suspends it and queues a wipe of the device in use. No fake data, no fake error.
4. **Stolen device**: "Mark lost" on a device = revoke + queue a wipe (existing wipe flow); progressive delay on failed sign-ins, temporary lock, and after repeated locks the account needs an admin unlock; alert to admins. The offline limit (72 h) already locks the local app.
5. **Maintenance mode**: Super Admin schedules or starts a window with a message and end time; everyone else gets an honest 503 page or JSON with `Retry-After`; the Super Admin and holders of a secret bypass link keep access; offline clients keep queueing.
6. **Role-limited access**: a proper 403 page explaining which permission is missing; broker scoping verified; an **Access review** report (accounts, role, permissions, last sign-in, dormant accounts, pending setup).

## Docs
Feature list (`docs/features.md`), runbook (`docs/runbook-access-security.md`), summary, threat model.
