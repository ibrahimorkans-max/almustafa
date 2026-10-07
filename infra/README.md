# Infrastructure

## Option A — Docker (recommended where available)

```bash
cp app/.env.example app/.env
docker compose -f infra/docker-compose.yml run --rm app composer install
docker compose -f infra/docker-compose.yml run --rm app php artisan key:generate
docker compose -f infra/docker-compose.yml up -d --build
docker compose -f infra/docker-compose.yml exec app php artisan migrate --seed
# → http://localhost:8080/admin
```

Set `RICEMILL_DB_PASSWORD` and `POSTGRES_SUPERUSER_PASSWORD` in your shell (or an `infra/.env`) for anything
beyond a throw-away local stack. [db/init/01-create-app-role.sh](db/init/01-create-app-role.sh) creates the
application role **without** superuser/BYPASSRLS — PostgreSQL superusers ignore row-level security.

## Option B — Windows without Docker (how phase 1 was built)

Portable tools in `C:\Users\<you>\dev\tools` (outside the repo):

| Tool | Source |
|---|---|
| PHP 8.3 NTS x64 | windows.php.net (enable `intl, mbstring, openssl, pdo_pgsql, pdo_mysql, sodium, zip, gd, fileinfo, curl`) |
| Composer | getcomposer.org `composer.phar` |
| PostgreSQL 17 binaries | EnterpriseDB "binaries" zip |

```powershell
# one-time cluster on port 5433 (XAMPP's MariaDB keeps 3306)
pgsql\bin\initdb -D pgdata -U postgres -A scram-sha-256 --pwprompt -E UTF8 --locale=C
pgsql\bin\pg_ctl -D pgdata -o "-p 5433 -c listen_addresses=127.0.0.1" -l pg.log start
```

```sql
-- as postgres:
CREATE ROLE ricemill LOGIN PASSWORD '…' NOSUPERUSER NOBYPASSRLS;
CREATE DATABASE ricemill OWNER ricemill;
CREATE DATABASE ricemill_test OWNER ricemill;
-- in each database:
CREATE EXTENSION IF NOT EXISTS pgcrypto; CREATE EXTENSION IF NOT EXISTS citext;
```

Start PostgreSQL after a reboot:

```powershell
C:\Users\<you>\dev\tools\pgsql\bin\pg_ctl -D C:\Users\<you>\dev\tools\pgdata -o "-p 5433 -c listen_addresses=127.0.0.1" -l C:\Users\<you>\dev\tools\pg.log start
```

`app/.env` (dev) and `app/.env.testing` (tests: `APP_KEY`, `DB_PASSWORD`) hold the secrets; both are git-ignored.

## MariaDB / MySQL (shared-hosting pilot)

Same migrations; no row-level security (application scoping only). Run the suite with
`vendor/bin/pest -c phpunit.mariadb.xml` against a `ricemill_test` database and a `ricemill` user.

## Production (Hostinger VPS)

Nginx/TLS 1.3/HSTS config, Supervisor, cron, deploy script, UFW/Fail2ban checklist, encrypted off-site
backups and the restore guide are delivered in phase 9.
