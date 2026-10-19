# Production on a VPS (Ubuntu 24.04, e.g. Hostinger KVM)

For the mill-specific steps (creating Al Mustafa Rice Mills, NTN / STRN, tax settings, master data) see [docs/deployment-checklist.md](../../docs/deployment-checklist.md).

Supported production target: one VPS with nginx, PHP 8.3-FPM, PostgreSQL, Supervisor and cron. Docker (in `../`) is
for development.

## 1. Before you start
* A domain whose DNS `A` record points at the VPS (needed for the TLS certificate).
* An SSH key pair for deployments. The private key goes into GitHub (below); the public key is `DEPLOY_KEY`.
* An S3-compatible bucket for off-site backups (Backblaze B2, Cloudflare R2, Wasabi…) with a key that can only write to
  it, and object lock / versioning if offered.

## 2. Provision (once, as root)
```bash
git clone <repository url> /root/ricemill-setup
cd /root/ricemill-setup
DOMAIN=erp.example.in ADMIN_EMAIL=you@example.in DEPLOY_KEY="ssh-ed25519 AAAA... deploy" bash infra/vps/provision.sh
```
**Mill server (no internet needed):** `TLS_MODE=local DOMAIN=ricemill.local bash infra/vps/provision.sh` — the mill's
own certificate, the name ricemill.local, open to the mill network only. Updates without internet:
`make-offline-release.sh` then `deploy.sh <package>.tar.gz`; USB backups: `copy-backups-to-usb.sh`. See
[docs/mill-server.md](../../docs/mill-server.md).
It installs and hardens the server: SSH keys only, firewall (22/80/443), fail2ban, automatic security updates, swap,
PHP-FPM pool running as `ricemill`, nginx with TLS and rate limits on login and API, PostgreSQL with an application
role that is **not** superuser and has **no** BYPASSRLS (row-level security would not apply otherwise), the `ricemill`
and `ricemill_drill` databases, queue workers, the scheduler and log rotation.

It writes the secrets file `/etc/ricemill/ricemill.env` (root:ricemill, mode 0640) with fresh random values.
**Copy `RICEMILL_BACKUP_KEY` somewhere safe off the server now** (password manager, sealed envelope with the owner).
Without it, no backup can ever be restored.

Then edit `/etc/ricemill/ricemill.env`: mail (SMTP), `RICEMILL_AUDIT_ANCHOR_EMAIL` (the owner), the off-site bucket
(`RICEMILL_OFFSITE_*`).

## 3. First deploy (on the server, as ricemill)
```bash
sudo -iu ricemill
REPO=<repository url> bash /root/ricemill-setup/infra/vps/deploy.sh main
cd /srv/ricemill/current/app && php artisan db:seed --class=AccessCatalogSeeder --force
```
Create the first Super Admin as described in the main README, with `RICEMILL_BOOTSTRAP_TRUST_FIRST_DEVICE=true` only for
that first sign-in, then set it back to `false`.

## 4. Later deploys (GitHub → Actions → Deploy → Run workflow)
Repository secrets / `production` environment:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | the server's host name or IP |
| `DEPLOY_SSH_KEY` | the deploy private key |
| `DEPLOY_KNOWN_HOSTS` | output of `ssh-keyscan <host>` (verify the fingerprint) |

Give the `production` environment a required reviewer. The workflow refuses commits whose CI is not green.

`deploy.sh` clones the exact commit into `/srv/ricemill/releases/<time>`, links shared storage and the secrets file,
installs dependencies, runs **`ricemill:preflight --before-migrate` (any FAIL stops here)**, takes a backup, migrates, runs the full preflight again, builds caches,
switches the `current` symlink atomically, reloads PHP-FPM and the workers, and checks `/health`. If the new release is
unhealthy, the code is switched back automatically. The last 5 releases are kept.

## 5. Running it
| What | Where / how |
|---|---|
| Health for an uptime monitor | `https://<domain>/health` (200 ok / 503 degraded; no secrets) |
| Backups | nightly 01:30 PKT → `/srv/ricemill/shared/storage/backups` + off-site; keeps 14 daily, 8 weekly, 12 monthly |
| Restore drill | Sundays 04:00 PKT into `ricemill_drill`, runs the audit, books and stock verifiers; `php artisan backup:verify` by hand |
| Alerts | failed tasks, failed jobs, failed restore drill, failed-login bursts → in-app alerts to Super Admins and owners |
| Logs | `/srv/ricemill/shared/storage/logs` (daily, 30 days), `/var/log/nginx/ricemill-*.log` |
| Readiness | `php artisan ricemill:preflight` |

## 6. Restoring for real (disaster recovery)
1. New server: provision as above, put the **same** `RICEMILL_BACKUP_KEY` and `APP_KEY` into `/etc/ricemill/ricemill.env`
   (`APP_KEY` decrypts NTN / CNIC / STRN / IBAN / 2FA secrets inside the data).
2. Copy the latest `.rmbak` from the off-site bucket into `/srv/ricemill/shared/storage/backups`.
3. `php artisan backup:verify <file>` — proves the file restores and verifies (into `ricemill_drill`).
4. Decrypt and restore into the live database during a maintenance window:
   ```bash
   php artisan down
   php artisan tinker --execute='App\Domain\Ops\Backup\BackupCrypto::decryptFile("storage/backups/<file>", "/tmp/b.zip", App\Domain\Ops\Backup\BackupCrypto::key(config("ricemill.backup.key")));'
   unzip /tmp/b.zip -d /tmp/b && PGOPTIONS='-c app.rls_bypass=on' pg_restore --enable-row-security --no-owner --no-acl --clean --if-exists -h 127.0.0.1 -U ricemill -d ricemill /tmp/b/database.dump
   rsync -a /tmp/b/files/ /srv/ricemill/shared/storage/app/private/files/
   php artisan audit:verify && php artisan books:verify && php artisan stock:verify && php artisan up
   shred -u /tmp/b.zip && rm -rf /tmp/b
   ```
5. Compare the audit-chain heads with the latest anchor e-mail.
