# Phase 9 — Plan: Hardening, backups, monitoring and deployment (Hostinger VPS)

## 1. Production readiness check
`php artisan ricemill:preflight` — one command that refuses to call a server "ready" unless:
`APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` and a separate blind-index key set, HTTPS `APP_URL`, secure session
cookies, HSTS on, Super Admin IP allow-list on, first-device bootstrap off, the database login is **not** a superuser
and cannot bypass row-level security (PostgreSQL), RLS is forced on every tenant table, the queue worker and the
scheduler have checked in recently, backups are configured and recent, storage is not web-readable, and `.env` is not
readable by other users. Output: a pass / fail list; exit code for CI and the deploy script.

## 2. Backups and restore drills
* `backup:run` — `pg_dump` (custom format) / `mysqldump` + the content-addressed file store, packed and **encrypted**
  (libsodium secretstream, key from the secret store / `.env`, never stored with the backup), with a manifest
  (sizes, SHA-256, app version, audit-chain heads). Kept locally with retention (daily 14, weekly 8, monthly 12) and
  copied off-site to any S3-compatible bucket (Hostinger Object Storage, Backblaze B2, AWS S3) when configured.
* `backup:verify` — the **restore drill**: decrypts the latest backup into a scratch database, migrates it, and runs
  `audit:verify`, `books:verify` and `stock:verify` against it; result recorded and alerted on failure. Weekly.
* **Audit-chain anchoring**: the daily head hash of every audit chain is written into the backup manifest and, if
  configured, e-mailed to the owner — a later rewrite of history would no longer match what was sent out.

## 3. Monitoring
* `GET /health` (no secrets): database, cache, disk space, queue backlog, last scheduler / worker heartbeat, last
  successful backup; HTTP 200 / 503 for an uptime monitor.
* Scheduler and queue heartbeats; failed jobs and failed scheduled commands raise in-app alerts to Super Admins.
* Logs as daily files with retention (JSON optional), no secrets or full identifiers in logs.

## 4. Deployment on a Hostinger VPS (Ubuntu 24.04)
* `infra/vps/provision.sh` (idempotent, run once as root): non-root `ricemill` user, SSH keys only, UFW (22/80/443),
  fail2ban, unattended security upgrades, PHP 8.3-FPM + OPcache, nginx (TLS via certbot, security headers, upload
  limits), PostgreSQL 16 (app role without superuser / BYPASSRLS), Supervisor for the queue worker, cron for the
  scheduler, log rotation, swap.
* `infra/vps/deploy.sh`: release directories + atomic `current` symlink, `composer install --no-dev`,
  `ricemill:preflight`, migrations, caches, worker reload, health check, automatic rollback if the health check fails.
* GitHub Actions `deploy` workflow (manual, protected environment) running `deploy.sh` over SSH after CI passes.
* Secrets only in `/etc/ricemill/ricemill.env` (0600, owned by root, read by PHP-FPM via the pool), never in Git.
* MySQL / shared-hosting pilot notes kept, but the VPS + PostgreSQL is the supported production target.

## 5. Hardening items carried from earlier phases
* Agent: protect the gate PC's signing secret and store key with **Windows DPAPI** (machine-bound) instead of plain
  config; fallback stays the environment variables.
* Login throttling and lockout monitoring: alert on bursts of failed logins / locked accounts.
* Dependency and secret scanning in CI (`composer audit`, a secret-pattern check over the repo).
* Performance: indexes for the busiest lists and reports; OPcache / realpath cache settings; config / route / view caches
  in deploy.

## Tests (planned)
Preflight passes on a correctly configured test environment and fails each rule independently; backup → encrypted
archive → manifest → restore drill into a scratch database with all verifiers passing, wrong key refused, tampered
archive refused; health endpoint states; heartbeats and failure alerts; agent DPAPI round-trip (Windows) and fallback;
shell scripts linted (`bash -n`, shellcheck when available); both databases.

## Assumptions (change if wrong)
* Hostinger **VPS** (KVM, Ubuntu 24.04) with root SSH for provisioning; one server for app + database to start.
* Off-site backups to an S3-compatible bucket the mill provides; without it, backups stay local and preflight warns.
* Domain and DNS for HTTPS are available at deployment time.
