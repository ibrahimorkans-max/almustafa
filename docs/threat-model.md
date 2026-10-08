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

## Phase 2 additions

| Threat | Controls | Remaining |
|---|---|---|
| Vendor bank account swapped to divert payments | Bank accounts change only via maker-checker; checker needs `bank.view`; numbers encrypted at rest and inside the pending request; masked everywhere else | Penny-drop / name-match verification (phase 5) |
| Replayed or reordered device events | Idempotent `event_id`, increasing `client_seq` per device, device-bound tokens | Request signing for the gate agent (phase 3) |
| Device clock set ahead to win conflicts | Event time clamped to server receipt time; every overwrite logged with both sides | — |
| Stale device floods old data | Events older than `max_offline_hours` go to supervisor review; client locks itself | — |
| Lost / stolen phone or tablet | Remote wipe instruction on next contact, then sessions end; sign-out wipes local data | Browser storage is not encrypted at rest (OS/disk encryption assumed); Flutter app uses SQLCipher (phase 10) |
| XSS in the PWA stealing tokens from IndexedDB | Strict CSP (scripts/styles from self only, no inline), DOM built with `textContent`, no third-party scripts | Move refresh token to an HttpOnly cookie if a web view without IndexedDB isolation is ever needed |
| Cross-entity data via guessed record ids | Scope + RLS on every master; an id belonging to another entity can only produce a review item, never an overwrite (tested) | — |

## Phase 3 additions

| Threat | Controls | Remaining |
|---|---|---|
| Typed / edited weights at the weighbridge | No weight entry on screens; indicator samples as evidence re-checked by the server; weighments append-only; manual value only by Super Admin + reason + second-person approval, flagged on the slip | Indicator sealing / tamper switch input (phase 6 hardware) |
| Truck tare manipulated (water tank, extra person) | Repeat-tare check against the vehicle's recent median, flagged | Axle / camera analytics (later) |
| Uncalibrated scale | Stamping certificate validity checked per weighment (flag or block); expiry reminders | — |
| Photo swapped after the fact | Photo SHA-256 stored on the weighment; content-addressed, re-verifiable | — |
| Altered printed slip | HMAC verification code over the frozen slip snapshot | Public verification page (phase 8) |
| Stolen agent token used from elsewhere | Device-bound tokens + per-device HMAC signature with timestamp and single-use nonce | Hardware-backed key store on the gate PC (phase 9) |
| Deal terms changed after approval | Approved sauda terms frozen in code; lifecycle as append-only events; maker ≠ checker | Amendment workflow (later) |
| Quality deductions changed retroactively | Rules frozen into each lab decision | — |

## Phase 4 additions

| Threat | Controls | Remaining |
|---|---|---|
| Stock quietly edited to hide pilferage | Stock ledger append-only (DB triggers); balances only through `StockLedger` under row locks; nightly `stock:verify` recomputes balances from the ledger | Off-site anchoring of ledger hashes (phase 9) |
| Paddy issued without authority | Stores issue only against a requisition approved by a different *person*; direct plant input needs `production.log` and is logged per device and hour | — |
| Production figures massaged to hide loss | Logs are append-only; corrections are reversing logs with a reason; yield and the standards used are frozen at batch close; unaccounted loss above the limit raises a critical alert | Machine-counter / bagging-scale integration (later) |
| Count "adjusted" to match the books | Count captures the books figure at counting time; difference posted only after second-person approval; shrinkage alert raised immediately and acknowledged with a note | Blind counts (counter does not see the books figure) as an option |
| Race between two issues overdrawing a godown | Row lock on the balance; negative stock refused; offline devices get `retry` | — |
| Notification job wiping the request's tenant context | Context reset skipped for jobs run inline on the `sync` queue (found in phase 4, regression-tested) | — |

## Phase 5 additions

| Threat | Controls | Remaining |
|---|---|---|
| Books edited after the fact | Vouchers and lines append-only (DB triggers); reversal vouchers via maker-checker; nightly `books:verify`; locked months reopen only with a second person | Off-site anchoring (phase 9) |
| Inflated purchase bill | Bill amounts computed from the frozen slip and frozen sauda terms, never typed; draft reviewed before posting | — |
| Payment diverted to a different account | Payee only from the party's approved bank account (itself maker-checker), frozen in the request; bank file hash re-checked on every download; spreadsheet formula injection neutralised in the CSV | Penny-drop / name-match verification with the bank |
| One person paying themselves | Release by a different *person* with `payment.approve`; above the limit a third person excluding requester and first approver | — |
| Double payment of a bill | Allocations limited to the outstanding amount, counting requests still awaiting approval | — |
| Cash withdrawal disguised as an adjustment | Bank and cash ledgers cannot be used in journals; cash payments capped per person per day (s. 40A(3)) | — |
| Cost / margin leakage | Valuation rates and stock value visible only to accounts roles (`ledger.view` / `ledger.post`); figures hidden, never altered | Field-level checks across all reports (phase 7) |

## Trust boundaries
Browser/PWA ↔ Nginx (TLS) ↔ PHP-FPM ↔ PostgreSQL/Redis · Gate agent ↔ API (signed requests, ph. 3) ·
Agent ↔ hardware (serial/IO, local only).

## Assumptions
* The app DB role is NOSUPERUSER/NOBYPASSRLS. RLS defends against query mistakes, not against code execution on
  the app server (which could set the bypass flag) — that case relies on audit, backups and host hardening.
* `APP_KEY` compromise exposes encrypted fields and recovery-code HMACs → key rotation runbook in phase 9.

## Review cadence
Updated at the end of every phase; security review + penetration test checklist in phase 9.
