# Rice Mill ERP

Secure, offline-capable, multi-mill ERP for rice mills in India.
Laravel 12 · PHP 8.3 · PostgreSQL 17 (row-level security) · Filament 3 · Pest.

**Non-negotiables:** true records only (no altered or second set of figures, ever), append-only
ledgers and audit, offline-capable gate/weighbridge/lab, security by design, everything auditable.

## Repository layout

| Path | What |
|---|---|
| [app/](app/) | Laravel application: API, Filament back office, domain code, migrations, tests |
| [agent/](agent/) | Python hardware agent (weighbridge, ANPR, boom barrier, display) — phase 3 |
| [mobile/](mobile/) | Flutter offline app — phase 10 |
| [infra/](infra/) | Docker Compose (php-fpm, nginx, PostgreSQL, Redis), DB bootstrap |
| [docs/](docs/) | Plan, ADRs, threat model, phase summaries |
| [.github/workflows/](.github/workflows/) | CI: Pint, Larastan, composer audit, Pest on PostgreSQL **and** MariaDB |

## Quick start

See [infra/README.md](infra/README.md) for Docker and for a Docker-free Windows setup (portable PHP 8.3 + PostgreSQL).

```bash
cd app
composer install
php artisan migrate --seed            # local env also seeds demo mills + one user per role
php artisan serve                     # http://127.0.0.1:8000/admin
```

Demo passwords are random and written to `app/storage/app/private/demo-credentials.txt` (git-ignored).
Every demo user must change the password at first sign-in; Owner/Accountant/Super Admin must set up 2FA.

## Tests

```bash
cd app
vendor/bin/pest                          # PostgreSQL (incl. row-level security tests)
vendor/bin/pest -c phpunit.mariadb.xml   # MariaDB/MySQL (shared-hosting pilot)
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

## Status

| Phase | Scope | State |
|---|---|---|
| 1 | Laravel setup, Docker, auth, 2FA, roles/permissions, entities + RLS, audit log, Filament shell | **done** — [summary](docs/phase-1-summary.md) |
| 2 | Masters, sync API, PWA offline shell | next |
| 3–10 | see [docs/phase-1-plan.md](docs/phase-1-plan.md) and the project brief | planned |
