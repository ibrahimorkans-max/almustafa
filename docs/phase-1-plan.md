# Phase 1 — Plan: Laravel setup, auth, 2FA, roles/permissions, entities + RLS, audit log, Filament shell

## 1. Folder structure

```
/app                      Laravel 12 project (PHP 8.3)
  app/
    Domain/Tenancy/       CurrentEntity context, BelongsToEntity trait + global scope, RLS session setter
    Domain/Auth/          login, lockout, TOTP 2FA, sessions, devices, refresh-token rotation
    Domain/Access/        permission catalog, role templates, PermissionService
    Domain/Audit/         hash-chained AuditLogger + verifier
    Domain/Approvals/     maker-checker service + action handlers
    Http/Controllers/Api/V1/   thin controllers
    Http/Requests/        Form Requests (validation)
    Http/Resources/       API Resources (output)
    Http/Middleware/      entity context, device binding, super-admin allowlist, security headers, MFA
    Models/               Eloquent models (UUID keys)
    Policies/
    Filament/             admin panel: resources, pages (MFA, sessions, audit viewer)
  database/migrations/    every table, plus Postgres RLS + immutability triggers
  database/seeders/       realistic sample entities, users per role
  tests/Feature, tests/Unit   Pest
/agent                    Python hardware agent (skeleton; drivers + simulators in phase 3)
/mobile                   Flutter app (phase 10)
/docs                     architecture, ADRs, threat model, phase notes
/infra                    docker-compose (php-fpm, nginx, postgres, redis), nginx/supervisor/cron, scripts
/.github/workflows        CI: Pint, Larastan, Pest on PostgreSQL and MariaDB, composer audit
```

## 2. Phase 1 data model

| Table | Purpose | Isolation |
|---|---|---|
| `entities` | Firms/mills; GSTIN/PAN as encrypted casts | own entity; super admin all |
| `persons` | A real human; owns 0..n login accounts | global (no business data) |
| `login_accounts` | Auth user: one entity + one role; username, Argon2id hash, TOTP secret (encrypted), lockout counters, status | `entity_id` |
| `mfa_recovery_codes` | Hashed one-time codes | `entity_id` |
| `permissions` | Global catalog (`deal.approve`, `pricing.margin.view`, …) | global, read-only |
| `roles` | Per-entity roles seeded from templates; global Super Admin role (`entity_id` NULL) | `entity_id` (+ global) |
| `role_permissions` | Grants | `entity_id` |
| `devices` | Device registry per login account: active / revoked / wipe_pending / wiped, `trusted_at` | `entity_id` |
| `auth_sessions` | Every web or API login: device, IP, UA, MFA state, absolute expiry, revoked_at/by/reason | `entity_id` |
| `api_tokens` | Hashed access (15 min) + refresh (rotating, reuse ⇒ whole session revoked) tokens, bound to session+device | `entity_id` |
| `ip_allowlist` | CIDRs per login account (enforced for Super Admin) | `entity_id` |
| `approval_requests` | Maker-checker: action, payload, reason, maker/checker *person*, status, result | `entity_id` |
| `settings` | Global (`entity_id` NULL) and per-entity key/value | `entity_id` (+ global) |
| `audit_log` | Append-only, hash-chained per chain (`entity:<uuid>` or `global`) | `entity_id`; no UPDATE/DELETE ever |
| `audit_chain_heads` | Last seq/hash per chain, row-locked while appending | internal |

Common columns: UUID PK, `entity_id`, `created_at`, `created_by`, `updated_at`, `version` (optimistic lock).
`archived_at` arrives with business tables in phase 2+.

### Isolation: two layers
1. **Application:** `BelongsToEntity` trait adds a global scope (`where entity_id = current`) and fills
   `entity_id` on create. Works on PostgreSQL and MariaDB.
2. **Database (PostgreSQL only):** `ENABLE` + `FORCE ROW LEVEL SECURITY` on every tenant table with policy
   `entity_id = current_setting('app.entity_id')::uuid OR current_setting('app.rls_bypass') = 'on'`.
   Middleware sets `app.entity_id` per request (and job middleware per queued job). Even a raw
   `DB::table()` query that forgets the scope returns nothing from other entities.
   Bypass is set only for system work (migrations, scheduler) and Super Admin, always audited.

### Audit log
`hash = sha256(prev_hash ‖ canonical_json(row))`; per-chain head row locked with `SELECT … FOR UPDATE`
(portable to MariaDB). Written in the same DB transaction as the change. DB triggers reject
`UPDATE`/`DELETE` on `audit_log` on both PostgreSQL and MariaDB. `php artisan audit:verify` recomputes
every chain and reports the first broken link; also exposed as a Filament action.

## 3. Assumptions (stated, not blocking)
1. **Laravel 12** (meets "11+"); **Filament 3.3** (supports Laravel 12). PHP 8.3 portable build for dev.
2. **Custom RBAC instead of spatie/laravel-permission** — each login account has exactly one role in one
   entity, roles are per entity with a global Super Admin role, all keys are UUIDs and tables are under RLS.
   Spatie's polymorphic many-to-many model adds surface without benefit here. Gates still go through
   Laravel's `Gate`/Policies, so `can('deal.approve')` works everywhere. (ADR-0002)
3. **Custom hash-chained audit table** instead of spatie/activitylog (allowed by spec): activitylog rows are
   editable and not chained. (ADR-0003)
4. **2FA:** TOTP via `pragmarx/google2fa` + recovery codes, implemented directly (Fortify's 2FA is tied to
   its own views/user model; Filament v3 has no built-in MFA). Hardware keys (WebAuthn) in phase 9.
5. **Tokens:** Sanctum is not a fit for rotation + reuse detection + device binding, so API tokens are a
   small custom guard on `api_tokens` (opaque 256-bit, SHA-256 hashed). Filament uses the normal session
   guard, backed by `auth_sessions` so web sessions also appear in the session list and can be revoked.
   (ADR-0004)
6. **Login mode** setting `auth.login_mode`: `multi_account` (default) or `switcher` (a person signed in
   with one account can switch to their other accounts without a password, but the MFA rules of the
   target role still apply).
7. **Super Admin** works across entities by selecting a working entity (header `X-Entity-Id` on the API,
   switcher in Filament); every switch is audited. Super Admin sessions: 60 min absolute, 5 min access token.
8. **Databases:** PostgreSQL 17 primary (RLS tested). MariaDB (XAMPP 10.4 locally, MySQL on shared hosting)
   supported with application scoping only; the same Pest suite runs on both, RLS tests are skipped on MariaDB.
9. **Docker/Python** are not installed here: Docker files are written but not run locally; the Python agent is
   a skeleton until phase 3.
