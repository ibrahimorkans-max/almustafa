# ADR-0002: Custom RBAC instead of spatie/laravel-permission

**Status:** accepted (phase 1)

## Context
The brief suggests spatie/laravel-permission. Requirements: one role per login account, roles per entity, a global
Super Admin role, UUID keys, tables under RLS, grants changed only through maker-checker, and an exact
per-role test matrix.

## Decision
Small custom model: `permissions` (catalog synced from `PermissionCatalog`), `roles` (per entity, `entity_id NULL`
for Super Admin), `role_permissions`, `login_accounts.role_id`. Checks go through Laravel's `Gate`
(`Gate::before` answers catalog codes), so `can('deal.approve')`, `@can`, policies and Filament all work.

## Why not spatie
Its polymorphic many-to-many (`model_has_roles`, direct user permissions) allows states we must forbid
(several roles, direct grants that bypass review), needs UUID/teams customisation, and its cache would
sit outside the RLS model. The custom version is ~150 lines and fully tested.

## Consequences
* Escalation guards are ours to keep: you can only grant/assign permissions you hold; the Super Admin role is
  Super-Admin-only (tested).
* Accounts with pending 2FA enrollment or a forced password change hold **no** permissions.
