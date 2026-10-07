# ADR-0003: Custom hash-chained, append-only audit log

**Status:** accepted (phase 1)

## Decision
`audit_log` written only by `AuditLogger`, in the same transaction as the change. One chain per entity
(`entity:<uuid>`) plus `global`; `seq` is gap-free per chain (head row locked `FOR UPDATE`).
`hash = sha256(prev_hash ‖ canonical JSON of the row)`; JSON columns are stored as the exact text hashed.
Database triggers reject `UPDATE`/`DELETE` (and `TRUNCATE` on PostgreSQL) for every user; the model refuses saves.
`php artisan audit:verify` / the Filament "Verify integrity" button recompute every chain and report the first
break (edited row, deleted row, removed tail, re-hashed forgery).

Values: secrets are recorded only as `[set]`; confidential identifiers (GSTIN, PAN, later bank details) are masked
to the last 4 characters; everything else is recorded as-is (old → new).

## Why not spatie/activitylog
Its rows are ordinary editable records with no chaining, so tampering would be undetectable.

## Limits
Someone with table-owner rights can disable the trigger and rewrite the whole chain consistently.
Mitigations (phase 9): run the app as a non-owner role, ship chain heads off-server daily
(anchoring), and keep encrypted off-site backups.
