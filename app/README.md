# Al Mustafa Rice Mills ERP — Laravel application

## Code map

| Path | Responsibility |
|---|---|
| `app/Domain/Tenancy` | `TenantContext` (current entity + RLS session settings), `BelongsToEntity` trait, `EntityScope` |
| `app/Domain/Auth` | `LoginService` (password, lockout, 2FA), `MfaService` (TOTP, recovery codes), `TokenService` (rotating device-bound API tokens), `SessionService`, `DeviceService`, `AccountSwitcher`, `ApiTokenGuard`, `TenantAwareUserProvider` |
| `app/Domain/Access` | `PermissionCatalog` (all permission codes), `RoleTemplates`, `AccessCatalogSync`, `EntityProvisioner` |
| `app/Domain/Audit` | `AuditLogger` (only writer of `audit_log`), `AuditVerifier`, `AuditHasher`, `Auditable` model trait |
| `app/Domain/Approvals` | Maker-checker `ApprovalService` + actions (`ChangeRolePermissions`, `TrustDevice`) |
| `app/Domain/Settings` | Global / per-entity settings (e.g. login mode) |
| `app/Domain/Sync` | `SyncService` (push/pull), `SyncRegistry` (event types + permissions), `SyncFeed` (gap-free change feed), `ConflictLog`, `ConflictResolver`, handlers |
| `app/Models/Masters` | Master data on `MasterModel` (scope, audit, version, archive, change feed) |
| `app/Support/Values` | `Weight` (kg, bcmath; maund = 40 kg), `Money` (paisa, Rs grouping), `AmountInWords`, `PakistanIds` (CNIC/NTN/STRN/IBAN/vehicle/mobile) |
| `app/Domain/Procurement` | `RequirementService`, `QuoteService` (negotiation), `SaudaService` (lifecycle, maker-checker, deliveries, expiry) |
| `app/Domain/Gate` | `GateService` (facts + projection), `DeductionCalculator`, `TareCheck`, `SlipRenderer` |
| `app/Domain/Documents` | `DocumentNumbers` (gap-free per entity / series / July–June tax year), `PdfRenderer` (mPDF, shared letterhead, Urdu headings), `DocumentVerifier` |
| `app/Domain/Files` | `FileStore` (content-addressed private files) |
| `public/pwa` | Offline PWA: service worker, IndexedDB store, sync engine, gate vehicles screen |
| `app/Http` | Thin API controllers (`/api/v1`), Form Requests, API Resources, middleware |
| `app/Filament` | Back office: login with 2FA step, 2FA setup, password change, switch entity/account, resources |
| `app/Policies` | Authorization per model; permission codes are answered by `Gate::before` |
| `database/migrations` | Schema, PostgreSQL RLS policies, audit immutability triggers |
| `tests/` | Pest: isolation, RLS, audit tamper detection, auth/API, authorization matrix per role, maker-checker |

## Rules for new code (from phase 2 on)

1. Every tenant table has `entity_id` and its model uses `BelongsToEntity`; add the table to the RLS migration list.
2. Every business write happens in `DB::transaction()` and is audited (`Auditable` trait or `AuditLogger::record`).
3. Ledgers and stock tables are append-only: add them to the immutability triggers; corrections are reversal entries.
   Stock moves only through `StockLedger::post` (balance row lock, no negative stock) — never write `stock_balances` directly.
   Money moves only through `Books::post` (Dr = Cr in paise, open period); payments only through `PaymentService` + approval.
4. Money as integer paise (or `decimal(18,2)`), weights in kg — never floats.
5. Anything needing maker-checker is an `ApprovalAction`.
6. Controllers use method injection (controllers are cached on routes; tenant/actor context is request-scoped).
7. Future-dated columns use `dateTime` (MySQL `TIMESTAMP` ends in 2038).
8. Anything a device can create is a sync event type registered in `SyncRegistry`; ledger/stock/weighment types are append-only handlers.
9. Secrets inside approval payloads are stored encrypted (`*_encrypted`) and never displayed.

## Commands

```bash
php artisan access:sync [--export=permissions.json]   # sync permission catalog; run on every deploy
php artisan ricemill:create-super-admin <username>    # installation bootstrap (asks for password)
php artisan audit:verify [--entity=<uuid>]            # recompute audit hash chains (scheduled daily)
php artisan procurement:expire                        # expire quotes / saudas (scheduled daily)
php artisan scales:certificate-reminders              # weighbridge certificate expiry (scheduled daily)
php artisan stock:verify                              # recompute stock balances from the ledger (scheduled daily)
php artisan books:verify                              # every voucher balances (scheduled daily)
php artisan accounts:install                          # chart of accounts for entities created before phase 5
php artisan archive:run [--dry-run] [--months=24]     # archive closed records (scheduled monthly; never deletes)
php artisan anomalies:scan [--hours=24]               # explainable anomaly rules → alerts (scheduled nightly)
```

## API (v1)

`POST /api/v1/auth/login` `{username, password, otp?, device_uid, device_name?, platform}` →
`{status: ok|mfa_required, access_token, refresh_token, …}`;
`POST /auth/refresh` (header `X-Device-Id`), `POST /auth/logout`, `GET /auth/me`, `POST /auth/switch`,
`GET /sessions`, `DELETE /sessions/{id}`, `GET /audit`, `GET /audit/verify`.
Authenticated calls send `Authorization: Bearer …` and `X-Device-Id`; Super Admin may add `X-Entity-Id`.
Sync: `GET /sync/bootstrap`, `POST /sync/push`, `GET /sync/pull`, `POST /sync/wipe-ack`, `POST /files`.
Gate agents additionally sign every authenticated request (see OpenAPI description).
Full contract: [docs/api/openapi.yaml](../docs/api/openapi.yaml) (kept in step with the routes by a test).
