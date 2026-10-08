# Phase 9 — Summary: Hardening, backups, monitoring and deployment

## Built

| Area | What exists |
|---|---|
| Backups | `backup:run` (nightly 01:30 IST): `pg_dump` (or `mysqldump`) + file store + manifest (SHA-256 of every file, app version, audit-chain heads) → zip → libsodium secretstream encryption → local folder + off-site S3-compatible bucket; retention 14 daily / 8 weekly / 12 monthly; every run recorded in `backup_runs`. `backup:key` makes a key |
| Restore drill | `backup:verify` (Sundays 04:00 IST): decrypt, check every file against the manifest, restore into a separate drill database (the live one is refused), run the audit, books and stock verifiers there |
| Audit anchoring | After each backup the audit-chain head hashes are e-mailed to the owner (`RICEMILL_AUDIT_ANCHOR_EMAIL`) |
| Monitoring | `GET /health` (200 / 503; database, cache, disk, scheduler and queue heartbeats, age of the last good backup; no versions, hosts or paths). Failed scheduled tasks, failed queued jobs and bursts of failed sign-ins (default 30 in 15 min) raise critical in-app alerts to Super Admins and owners in every firm, once per hour |
| Preflight | `ricemill:preflight`: production env, debug off, https, secure cookies, real cache / queue / mail, Super Admin IP allow-list and trusted devices, bootstrap off, no demo credentials file, PostgreSQL, **DB login is not superuser / BYPASSRLS, RLS forced on every tenant table**, migrations, backup key / off-site / drill / anchor, `pg_dump` present, PHP extensions, OPcache, writable folders, free disk. Any FAIL → exit 1 |
| VPS | `infra/vps/provision.sh` (Ubuntu 24.04, idempotent): SSH keys only, UFW, fail2ban, unattended upgrades, swap, PHP 8.3-FPM pool as `ricemill` with OPcache, nginx with TLS (certbot) and rate limits on login and API, PostgreSQL role without superuser / BYPASSRLS, `ricemill` + `ricemill_drill` databases, Supervisor workers, cron scheduler, logrotate; secrets generated into `/etc/ricemill/ricemill.env` (0640) |
| Deploy | `infra/vps/deploy.sh`: release directory per deploy, shared storage + secrets linked, composer, **preflight**, backup, migrate, caches, atomic symlink switch, health check, automatic code rollback, keeps 5 releases. GitHub **Deploy** workflow: manual, `production` environment, refuses commits without green CI |
| CI | New job: secret scan over tracked files (keys, tokens, `APP_KEY` / backup key / password values, tracked `.env` files), `bash -n` and shellcheck on the scripts. PostgreSQL job now runs the real `pg_dump` → restore → verify drill |
| Gate agent | `ricemill-agent protect`: Windows DPAPI-protected secrets (`dpapi:…`) for the login password, signing secret and store key; machine or user scope; plain values and environment variables still work |
| Performance | Indexes for gate entries by vehicle, bills by party / status and date, saudas by product, alerts by type / subject |

## Tested
* **PostgreSQL:** 423 passed, 1 skipped. **MariaDB:** 412 passed, 12 skipped (PostgreSQL-only RLS and drill tests). Larastan level 6 and Pint clean. Agent: 31 tests
  (DPAPI round trip on Windows). Secret scan clean on the repository and catches a planted key.
* New: encryption round trip (empty, exact chunk, multi-chunk), wrong key, flipped byte, truncation, trailing data,
  foreign file; backup contents (dump, files, manifest with chain heads, anchor e-mail without the key), off-site copy,
  failed dump recorded, retention tiers; drill refuses the live / missing database and a changed backup before
  restoring; **real `pg_dump` → `pg_restore` → verifiers** (PostgreSQL, when the client tools are configured); health
  states and staleness, no sensitive data; queue heartbeat; failed-login burst alert per firm without repeats; scheduled
  task failure alert; preflight blocks a dev setup, passes a production setup, detects a table without forced RLS.
* Also run by hand on the dev database: `backup:run` + `backup:verify` restored 184 audit rows into `ricemill_drill`
  with RLS still forced.

## Fixed along the way
* `pg_dump` refused to dump tables with FORCEd row-level security. Dump and restore now use
  `--enable-row-security` with the app's own bypass setting (`PGOPTIONS=-c app.rls_bypass=on`) — RLS is not weakened.
* Laravel's default `s3` disk swallows write errors (`throw => false`); off-site copies use a dedicated `offsite`
  disk that throws, so a failed upload fails the backup.

## Not done / next
* Choosing the uptime monitor, SMTP provider and off-site bucket (configuration only).
* A real GSP adapter for e-invoices (provider seam from phase 8).
* `APP_KEY` rotation runbook (re-encrypting GSTIN / PAN / 2FA secrets).
