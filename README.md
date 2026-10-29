# Al Mustafa Rice Mills — ERP

Secure, offline-capable ERP for Al Mustafa Rice Mills, Adda Permat, 1 km Shujabad Road, Lodhran (Punjab, Pakistan) · 0300-6834588.
Pakistan rules: PKT, July–June tax year, Rs, maund (40 kg), NTN / STRN / CNIC / IBAN, sales tax and withholding u/s 153.
Printed slips, invoices, vouchers and statements carry the mill's letterhead in English with Urdu headings.
Laravel 12 · PHP 8.3 · PostgreSQL 17 (row-level security) · Filament 3 · Pest.

**Non-negotiables:** true records only (no altered or second set of figures, ever), append-only
ledgers and audit, offline-capable gate/weighbridge/lab, security by design, everything auditable.

## Repository layout

| Path | What |
|---|---|
| [app/](app/) | Laravel application: API, Filament back office, domain code, migrations, tests |
| [agent/](agent/) | Python hardware agent (weighbridge, camera, ANPR, boom barrier, display) — phases 3 and 6 |
| [mobile/](mobile/) | Flutter offline app — phase 10 |
| [infra/](infra/) | Docker Compose (php-fpm, nginx, PostgreSQL, Redis), DB bootstrap |
| [docs/](docs/) | Plan, ADRs, threat model, phase summaries |
| [docs/user-manual.md](docs/user-manual.md) | **User manual**: every screen, day to day, by module |
| [.github/workflows/](.github/workflows/) | CI: Pint, Larastan, composer audit, Pest on PostgreSQL **and** MariaDB |

## Quick start

See [infra/README.md](infra/README.md) for Docker and for a Docker-free Windows setup (portable PHP 8.3 + PostgreSQL).

```bash
cd app
composer install
php artisan migrate --seed            # local env also seeds demo mills + one user per role
php artisan serve                     # back office: http://127.0.0.1:8000/admin · gate PWA: /pwa/
```

Demo passwords are random and written to `app/storage/app/private/demo-credentials.txt` (git-ignored).
Every demo user must change the password at first sign-in; Owner, Super Admin and Accountant must set up 2FA.

Demo activity (also part of `migrate --seed` locally): two confirmed paddy saudas, trucks at every gate stage
(three finished, one at the lab, one unloaded waiting for tare, one just arrived waiting for gross) and weights
that are checked OK, queried or not checked yet. On an existing database:

```bash
php artisan db:seed --class=DemoActivitySeeder     # once per mill; a second run does nothing
php artisan ricemill:demo-live-weight              # pretend weighbridge readings for Live weight (15 min; --kg, --seconds)
```

The work is recorded by three demo staff logins (`amr.demo.clerk`, `amr.demo.manager1`, `amr.demo.manager2`)
whose passwords are random and never shown, so your own accounts can check the weights they recorded.

## Tests

```bash
cd app
vendor/bin/pest                          # PostgreSQL (incl. row-level security tests)
vendor/bin/pest -c phpunit.mariadb.xml   # MariaDB/MySQL (shared-hosting pilot)
vendor/bin/pint --test && vendor/bin/phpstan analyse
npm run test:js                          # PWA sync engine (node --test)
cd ../agent && python -m unittest discover -s tests   # gate agent
```

## Status

| Phase | Scope | State |
|---|---|---|
| 1 | Laravel setup, Docker, auth, 2FA, roles/permissions, entities + RLS, audit log, Filament shell | **done** — [summary](docs/phase-1-summary.md) |
| 2 | Masters, sync API, PWA offline shell | **done** — [summary](docs/phase-2-summary.md) · [API](docs/api/openapi.yaml) |
| 3 | Procurement & sauda, gate entry, weighbridge, lab, gate agent + simulators | **done** — [summary](docs/phase-3-summary.md) · [agent](agent/README.md) |
| 4 | Inventory, stock ledger, production, by-products | **done** — [summary](docs/phase-4-summary.md) |
| 5 | Accounts (double-entry, vouchers, purchase bills, payments, bank, valuation) | **done** — [summary](docs/phase-5-summary.md) |
| 6 | Sales, dispatch, invoices, ANPR / barrier / display | **done** — [summary](docs/phase-6-summary.md) |
| 7 | Claims (credit / debit notes), pricing with confidential margin, archive | **done** — [summary](docs/phase-7-summary.md) |
| 8 | Tax registers, reports, dashboard, anomaly alerts, document verification (Indian GST parts replaced: see [Pakistan localisation](docs/pakistan-localisation-summary.md)) | **done** — [summary](docs/phase-8-summary.md) |
| 9 | Hardening, backups, monitoring, deployment | **done** — [summary](docs/phase-9-summary.md) |
| — | Access security (suspend / revoke, lockdown, duress PIN, stolen devices, maintenance, access review) | **done** — [summary](docs/access-security-summary.md) |
| — | Pakistan localisation (PKT, July–June tax year, Rs and maund, sales tax, s. 153, Urdu documents) | **done** — [summary](docs/pakistan-localisation-summary.md) |
| HR 1–4 | Employees, attendance on phones with integrity checks, review queue, leave, shifts, holidays, register | **done** — [1](docs/hr-phase-1-summary.md) · [2](docs/hr-phase-2-summary.md) · [3](docs/hr-phase-3-summary.md) · [4](docs/hr-phase-4-summary.md) |
| HR 5–9 | Wages, payroll with EOBI / PESSI / s. 149, payslips and documents, delivery and self-service | **done** — [5](docs/hr-phase-5-summary.md) · [6–7](docs/hr-phase-6-7-summary.md) · [8](docs/hr-phase-8-summary.md) · [9](docs/hr-phase-9-summary.md) |
| HR 10–12 | Staff claims, contractor bills, expenses, profit and loss, dashboards, hardening | **done** — [10](docs/hr-phase-10-summary.md) · [11](docs/hr-phase-11-summary.md) · [12](docs/hr-phase-12-summary.md) |

**Going live:** [docs/go-live.md](docs/go-live.md).
