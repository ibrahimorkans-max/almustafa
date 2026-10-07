# ADR-0001: Entity isolation = application scope + PostgreSQL row-level security

**Status:** accepted (phase 1)

## Context
Each mill (entity) has its own books, stock and parties. A missing `where entity_id = ?` must not leak data.
Production may also run on MySQL shared hosting for a pilot, which has no row-level security.

## Decision
Two independent layers:
1. **Application:** `BelongsToEntity` adds a global scope and fills/guards `entity_id` on write. With no context,
   queries match nothing (fail closed). Writing into another entity throws `CrossEntityWrite`.
2. **PostgreSQL RLS:** every tenant table has `ENABLE` + `FORCE ROW LEVEL SECURITY` and a policy on
   `app.entity_id` / `app.rls_bypass`, which `TenantContext` sets on the connection per request (and per
   queued job). `FORCE` makes it apply to the table owner, so the app user (which owns the tables) is filtered.

Pre-authentication lookups (username, token hash) and provisioning run in an explicit `runAsSystem()` block.
Super Admin runs with bypass on and a chosen working entity.

## Consequences
* Raw `DB::table()` queries are protected on PostgreSQL; on MariaDB only Eloquent is. Code review rule:
  no raw tenant queries outside `Domain/*` services.
* RLS protects against query bugs, not against compromised application code (which can set the bypass flag).
  That risk is covered by the audit log, least-privilege DB credentials and the threat model.
* The app DB role must be NOSUPERUSER/NOBYPASSRLS — enforced in Docker init, CI and docs.
* Tests prove both layers (`tests/Feature/Tenancy`).
