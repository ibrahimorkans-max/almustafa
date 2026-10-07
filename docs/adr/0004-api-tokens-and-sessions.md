# ADR-0004: Device-bound rotating API tokens (not Sanctum) + server-side sessions

**Status:** accepted (phase 1)

## Decision
* `auth_sessions`: one row per sign-in (web or API) with device, IP, MFA time, absolute expiry (role's
  `session_max_minutes`: 60 for Super Admin, 720 otherwise), revocation. Web sessions also have a 30-minute idle
  limit. Every panel request re-validates its row, so revocation is immediate.
* `api_tokens`: opaque 256-bit tokens, SHA-256 hashed. Access 15 min (5 for Super Admin); refresh is single-use.
  Presenting a used refresh token revokes the whole session (reuse detection). Tokens only work with the
  `X-Device-Id` of the device they were issued to; a refresh from another device revokes the session.
* Devices: registered per account; revoke and remote-wipe flags; Super Admin devices must be approved by a
  **different** Super Admin (maker-checker), except the very first one during installation.

## Why not Sanctum
Sanctum personal tokens have no refresh/rotation, no reuse detection and no device binding.

## Open items
* WebAuthn / hardware keys (phase 9).
* Max offline duration before forced re-authentication is enforced by the sync API (phase 2).
