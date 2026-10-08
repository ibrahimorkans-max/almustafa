# ADR-0012: Encrypted, drilled backups with audit anchoring; health and heartbeats; release-directory deploys

## Status
Accepted — Phase 9.

## Context
The mill's records are the evidence in disputes with farmers, buyers and tax authorities. A backup that has never been
restored is a hope, not a backup; a backup that can be read by whoever finds it is a data leak; and an audit log
that the server's own administrator could rewrite proves little. Production is a single Hostinger VPS (Ubuntu 24.04)
looked after by a small team without an operations department.

## Decision
1. **Vendor-native dumps, encrypted end to end.** `pg_dump --format=custom` (or `mysqldump` on the pilot path) plus the
   file store go into one zip with a manifest (SHA-256 of every file, app version, audit-chain heads), encrypted with
   libsodium secretstream (XChaCha20-Poly1305, 64 KB chunks, FINAL tag → truncation, tampering and a wrong key are all
   detected). The 32-byte key lives in the secret store and **must also be kept off the server**; it is never inside a
   backup. The dump is restorable with standard PostgreSQL tools after decryption, without this application.
2. **Row-level security stays FORCEd.** Dump and restore run with `--enable-row-security` and the application's own
   system bypass switch (`PGOPTIONS=-c app.rls_bypass=on`) instead of weakening RLS for the table owner.
3. **Restore drill every week.** `backup:verify` decrypts the newest backup, checks every file against the manifest,
   restores into a separate drill database (refuses the live one) and runs the audit-chain, books and stock verifiers
   there. Result recorded in `backup_runs`; a failure is an alert.
4. **Audit anchoring.** The manifest's audit-chain head hashes are e-mailed to the owner after every backup. Someone
   with full server access could rebuild a consistent chain, but not change the owner's mailbox, so a rewrite becomes
   detectable outside the system.
5. **Retention 14 daily / 8 weekly / 12 monthly**, local plus an off-site S3-compatible bucket (write-only key, object
   lock where the provider offers it).
6. **Monitoring without new services.** `GET /health` (200 / 503, states and ages only) for any uptime monitor;
   scheduler and queue heartbeats; failed scheduled tasks, failed jobs and failed-login bursts raise in-app alerts to
   holders of `audit.verify` (Super Admins and owners) in every firm.
7. **Preflight gates every deploy.** `ricemill:preflight` checks configuration, security switches, that the DB login
   is not a superuser / BYPASSRLS, that RLS is forced on every tenant table, pending migrations, backup settings,
   PHP extensions and disk. Any FAIL stops the deploy.
8. **Release directories + atomic symlink.** `deploy.sh`: clone the exact commit, link shared storage and the secrets
   file, composer, preflight, backup, migrate, cache, switch, health check, automatic rollback of the code. Migrations
   stay additive so the previous release runs on the new schema. The GitHub workflow is manual, behind a protected
   environment, and refuses commits without green CI.

## Consequences
* Losing the backup key means losing every backup — the installation steps say so twice.
* The drill needs a second database the application login owns; provisioning creates `ricemill_drill`.
* Code rollback is automatic; schema rollback is not (restore from the pre-deploy backup if ever needed).
* Off-site copies need `league/flysystem-aws-s3-v3`; the `offsite` disk throws on errors so a failed upload fails the
  backup run instead of passing silently.
