# HR module — Phase 3 summary: fake-location detection, integrity checks, review queue, manual attendance

Plan: [hr-phase-3-plan.md](hr-phase-3-plan.md). Signal contract: [api/openapi.yaml](api/openapi.yaml) (`POST /attendance/device` description).

## Built
| Area | What exists |
|---|---|
| Phone integrity signals | Evaluated on the server from the native app's `signals`. A location the phone reports as simulated (Android `isMock`, iOS `isSimulatedBySoftware`) is **rejected**. A fake-location app, root / jailbreak, hooking tools or an emulator are **flagged**. All signals are stored with the punch as evidence |
| Play Integrity / App Attest | Checked through the `IntegrityVerifier` interface. Until the mill's Google / Apple credentials are configured, tokens are recorded as **unverified**, never as passed; a failed verdict is flagged |
| PWA vs native | A browser cannot see these signals. With `RICEMILL_ATTENDANCE_REQUIRE_INTEGRITY=true` (once the native app is in use), a punch without signals or with an unverifiable token is flagged as "integrity unknown / not verified". Default off, so PWA punches are not all flagged |
| Server sanity checks (any client) | Impossible travel (above 100 km/h from the previous punch, ignoring jitter under 2 km); exactly repeated coordinates within 30 days; accuracy below 1 m; one phone used for another employee within 12 h; phone clock more than 5 min off the server. The uploading IP is stored with each punch |
| Review queue | **HR → Attendance**: "Final" column (server verdict, or the latest review), filter **Awaiting review**. The punch page shows a Review section with all decisions. Accept / Reject need a reason; reviewers need `hr.attendance.review` (Supervisor, HR Manager, Owner, Super Admin); nobody reviews their own punch. Decisions are a separate **append-only** table; the punch never changes; the latest decision counts. Only flagged punches are reviewed; a rule-rejected punch (changed clock, fake location) is corrected by manual attendance |
| Manual attendance | **Request manual attendance** on the attendance screen (`hr.attendance.override`, reason required, never for yourself, never in the future or before joining). Created only when a different person with `hr.attendance.review` approves (maker-checker); stored as source "manual" and linked to the approval request |
| Alert | One employee with 3 fake-location rejections or 5 tampering signs within 7 days → a critical alert to reviewers (once per employee per week), saying to look at the punches and talk to the employee before deciding |

## Tested
* 17 new tests: every phone signal (mock → rejected with evidence; root / hooking / emulator / fake-location app → flagged; iOS jailbreak), unverified tokens never reported as passed, a configured verifier (failed → flagged, passed → clean), the `require_integrity` switch; impossible travel (Karnal → Delhi in 20 min) and no false alarm for normal jitter, repeated coordinates and perfect accuracy, one phone for two employees, phone clock off; reviews (permission, reason, latest decision counts, punch unchanged, append-only at the database, only flagged punches, never your own), the queue filter and decision on the screen; manual attendance through maker-checker (not created before approval, the requester cannot approve, linked, correct IST time), refusals (future, own, missing reason), requested from the screen; the alert once per week.
* All HR tests: 67. **Full suites:** PostgreSQL 556 passed, 1 skipped; MariaDB 545 passed, 12 skipped (PostgreSQL-only tests). Larastan, Pint and the OpenAPI contract test are clean.

## Caught before release
* The manual-attendance time picker converted IST to UTC and the approval action then read it as IST again, so every manual punch would have been 5 h 30 min off. The picker now keeps IST wall time and the action converts it once (the test checks the stored time).

## Open decision (same as phase 1)
* Approving requests needs `approval.decide`, which today only Owner and Super Admin have. So manual attendance (like salary-account changes) is approved by the Owner, even though Supervisors and HR hold `hr.attendance.review`. Giving Supervisor / HR `approval.decide` would let them approve manual attendance, but also every other request type their permissions allow.

## Next: phase 4
Leave types, balances, requests and approvals; holidays and weekly offs; shifts (linking employees and sites); overtime, late and early-leave rules. Turning in / out pairs into worked days and hours, using each punch's **final** status. Biometric / gate-PC import.
