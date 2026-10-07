# Phase 1 — Summary

## Built
**Stack:** Laravel 12.69 on PHP 8.3, PostgreSQL 17 (primary, RLS), MariaDB/MySQL (pilot), Filament 3.3, Pest 3,
Larastan, Pint, Docker Compose, GitHub Actions.

| Area | What exists |
|---|---|
| Multi-entity | `entities`, persons with login accounts per entity (one per entity per person); `BelongsToEntity` scope + guard; PostgreSQL `FORCE` RLS on every tenant table; fail-closed without context; Super Admin working-entity switch (audited) |
| Auth | Argon2id; lockout (5 failures / 15 min, counts wrong 2FA codes); throttling; generic errors; web sessions backed by `auth_sessions` (absolute + idle expiry, instant revocation); device registry (revoke, remote-wipe flag) |
| 2FA | TOTP with QR enrollment, replay protection, 10 one-time recovery codes; mandatory for Super Admin, Owner, Accountant (no permissions until enrolled); admin reset with reason |
| API tokens | `/api/v1/auth/*`: device-bound access tokens (15 min, 5 for SA) + single-use rotating refresh tokens with reuse detection; session list/revoke; `/me`; account switch |
| Super Admin | Global role; IP allowlist; new devices need approval by a *different* Super Admin; 60-minute sessions |
| RBAC | 61 permission codes in `PermissionCatalog`, 9 role templates copied per entity; `Gate`/policies; escalation guards |
| Maker-checker | `ApprovalService` (different person, `approval.decide`, expiry, stale-state check, audited); actions: role permission change, trust Super Admin device |
| Audit | Append-only (DB triggers both DBs), per-entity hash chains, verifier (artisan + UI), secrets never stored, GSTIN/PAN masked |
| Admin (Filament) | Login with 2FA step; 2FA setup; change password; switch entity/account; Entities, Users (+IP allowlist), Roles (request change), Approvals, Audit log (+verify), Devices, Sessions, Settings (login mode) |
| Infra / CI | Docker Compose (php-fpm, nginx, postgres, redis, queue, scheduler) with non-superuser DB role; CI: Pint, Larastan L6, composer audit, Pest on PostgreSQL and MariaDB |

## Tested
* **PostgreSQL:** 145 passed, 1 skipped (1000 assertions). **MariaDB:** 136 passed, 10 skipped (RLS/tamper tests are PostgreSQL-only).
* Entity isolation (Eloquent and raw SQL / RLS, insert/update/delete across entities), Super Admin cross-entity.
* Audit: chaining, immutability for the app DB user, tamper detection (edit, delete, truncated tail, re-hashed forgery), same-transaction rollback.
* Auth: login, generic errors, lockout, rate limit, 2FA (wrong, replay, recovery code, enrollment required), token rotation and reuse detection, device binding, expiry, revocation, IP allowlist, trusted-device approval.
* **Authorization matrix for every role:** each of the 61 permission codes per role, each admin screen per role, audit API per role, unauthenticated access to every protected endpoint.
* Maker-checker: same person via a second account cannot approve, escalation blocked, rejection/expiry/withdrawal, stale request.
* Web: two-step login, broker blocked, forced 2FA / password change, idle + absolute timeouts, revoked session, headers.
* Static: Larastan level 6 clean; Pint clean; `composer audit` clean. Manual browser check of the sign-in → forced password change flow.

## Known gaps carried forward
* WebAuthn/hardware keys → phase 9 (TOTP enforced now).
* Request signing for the agent, offline re-auth limit, OpenAPI document → phase 2 with the sync API.
* Production Nginx/TLS/HSTS, backups, restore drill, Hostinger runbook, app DB role as non-owner → phase 9.
* Rate limiter uses the cache store (database locally); Redis on the VPS.

## Next: Phase 2 — Masters, sync API, PWA offline shell
1. Masters (parties: customers/vendors/brokers/farmers with encrypted GSTIN/PAN/bank + blind index, drivers,
   vehicles, products/brands with base unit kg, godowns, tax settings) with `archived_at`, versions, conflict log.
2. Sync contract (`sync_events`): client UUID, device, timestamp, version, idempotency key; ordered push, cursor pull,
   LWW + conflict log for masters, supervisor review queue; offline limits; remote-wipe delivery; OpenAPI.
3. PWA shell: service worker, IndexedDB queue, Online/Offline/Syncing badge with unsynced count.
