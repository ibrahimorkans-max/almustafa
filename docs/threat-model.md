# Threat model (living document — phase 1 baseline)

## Assets
Ledger and stock truth · weighments · prices, cost and margin (confidential) · party identifiers (GSTIN, PAN, bank) ·
credentials, 2FA secrets, tokens · the audit trail itself.

## Actors
Outsider on the internet · ex-employee · insider with a valid low-privilege account · broker/agent · insider with
admin rights · operator of the server/DB · thief of a phone or gate PC.

## STRIDE summary (phase 1 scope)

| Threat | Example | Controls in place | Remaining / later |
|---|---|---|---|
| **Spoofing** | Password guessing, credential stuffing | Argon2id; lockout after 5 failures (password or 2FA); rate limits per IP and per username; same message for unknown user / wrong password / locked; mandatory TOTP for Super Admin, Owner, Accountant | WebAuthn keys (ph. 9); breached-password check |
| | Stolen refresh token | Single-use refresh with reuse detection → whole session revoked; device binding | Device attestation for mobile (ph. 10) |
| | Stolen Super Admin password + phone | Trusted-device approval by a second Super Admin; IP allowlist; 60-min sessions, 5-min tokens | Hardware keys |
| **Tampering** | Edit a weight / ledger / audit row in DB | Audit log append-only (triggers), hash chain + verifier; optimistic locking on records | Append-only triggers on ledgers/weighments (ph. 3–5); daily off-site chain anchoring (ph. 9) |
| | Mass-assignment / cross-entity write | Fillable whitelists, strict model mode, `CrossEntityWrite` guard, RLS `WITH CHECK` | — |
| **Repudiation** | "I didn't approve that" | Every login, failure, switch, approval, role change audited with actor, person, device, IP, old/new | — |
| **Information disclosure** | Mill A reads Mill B | App scope + PostgreSQL RLS (FORCE), fail-closed without context; tests for raw queries | MySQL pilot has app scope only (documented trade-off) |
| | Margin/cost leak | Permission-gated fields (`pricing.margin.view`); values never altered, only hidden | Field-level tests per module (ph. 7) |
| | DB dump stolen | GSTIN/PAN/TOTP secrets encrypted (AES-256, `APP_KEY`); passwords Argon2id; tokens hashed | Key in secret store, encrypted backups (ph. 9) |
| **Denial of service** | Login flooding | Throttling; lockout | WAF / Fail2ban (ph. 9); lockout-as-DoS monitoring |
| **Elevation of privilege** | Owner grants himself entity.manage | Maker-checker; cannot grant/assign permissions you do not hold; Super Admin role only by Super Admin; maker ≠ checker by *person* | — |
| | Pending-2FA user uses the app | Account holds no permissions until 2FA enrolled / password changed | — |

## Trust boundaries
Browser/PWA ↔ Nginx (TLS) ↔ PHP-FPM ↔ PostgreSQL/Redis · Gate agent ↔ API (signed requests, ph. 3) ·
Agent ↔ hardware (serial/IO, local only).

## Assumptions
* The app DB role is NOSUPERUSER/NOBYPASSRLS. RLS defends against query mistakes, not against code execution on
  the app server (which could set the bypass flag) — that case relies on audit, backups and host hardening.
* `APP_KEY` compromise exposes encrypted fields and recovery-code HMACs → key rotation runbook in phase 9.

## Review cadence
Updated at the end of every phase; security review + penetration test checklist in phase 9.
