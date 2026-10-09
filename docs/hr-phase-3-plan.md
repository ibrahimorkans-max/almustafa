# HR module — Phase 3 plan: fake / mock location detection, integrity checks, review queue, manual attendance

Branch `hr-phase-3` (from `hr-phase-2`).

## 1. Phone integrity signals (evaluated on the server)
The native app sends what the operating system tells it, in `signals` of `attendance.punch` (contract in OpenAPI):

| Signal | Android | iOS | Verdict |
|---|---|---|---|
| `mock_location` | `Location.isMock` / `isFromMockProvider` | `CLLocationSourceInformation.isSimulatedBySoftware` | **rejected** (the location is known to be fake) |
| `mock_apps_enabled` | mock-location app selected in developer options | — | flagged |
| `rooted` / `jailbroken` | root checks | jailbreak checks | flagged |
| `hooking` | Xposed / Frida / Magisk detected | Frida / substrate | flagged |
| `emulator` | emulator build | simulator | flagged |
| `integrity` | Play Integrity verdict token | App Attest assertion | flagged when it fails or cannot be verified |

The Play Integrity / App Attest **token** must be checked with Google / Apple. That needs the mill's own API credentials, so it sits
behind an `IntegrityVerifier` interface. Until it is configured the token is recorded as "not verified", which is honest; it is never
reported as "passed". The PWA cannot see any of these signals, so a PWA punch carries none. With
`RICEMILL_ATTENDANCE_REQUIRE_INTEGRITY=true` (for when the native app is in use) a punch without signals is flagged as
`integrity_unknown`.

## 2. Server-side sanity checks (any client)
* **Impossible travel:** distance ÷ time since the employee's previous punch above 100 km/h (configurable) → flagged.
* **Identical coordinates:** exactly the same latitude / longitude (7 decimals) as an earlier punch in the last 30 days.
  Real GPS always jitters, so this points to replayed or spoofed positions → flagged.
* **Suspiciously perfect accuracy:** reported accuracy below 1 m → flagged.
* **One phone, several employees:** the same device punched for another employee in the last 12 hours → flagged.
* **Phone clock off:** the wall time sent with the upload differs from the server by more than 5 minutes → flagged (the
  phase 2 monotonic check still rejects a clock changed between punch and upload).
* **IP / network:** the uploading IP is stored with each punch as a soft signal. Region lookup needs a GeoIP database
  (seam `IpLocator`, off by default).

## 3. Review queue
* New table `attendance_reviews` (**append-only**): punch, decision (accept / reject), reason (required), reviewer, time.
* **HR → Attendance review**: flagged punches not yet decided, with all evidence (photo, map, both times, signals, phone,
  earlier punches). Accept or reject with a reason; a reviewer cannot decide their own punch. The original punch never changes;
  its **final status** = latest review decision, else the server's verdict.
* Permission `hr.attendance.review` (Supervisor, HR Manager, Owner, Super Admin).
* Alert: an employee with 3 or more punches rejected for fake location (or 5 flagged for integrity) within 7 days → alert to holders of
  `hr.attendance.review`.

## 4. Manual attendance and corrections
* `hr.attendance.override` + a required reason + **maker-checker** (approval action `hr.attendance.manual`): the punch is
  created only when someone else approves, as source `manual` with the approval request linked.
* A wrong punch is corrected by rejecting it in the review queue (reason) and entering the right one manually. Nothing is edited
  or deleted.

## Tests
Every signal rule. Each sanity check, with realistic coordinates. Review decisions (reason required, no self-review, final status,
append-only). Manual entry through maker-checker. The alert threshold. Screens with role access.
