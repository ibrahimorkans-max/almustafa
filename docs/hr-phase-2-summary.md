# HR module — Phase 2 summary: attendance punch flow, geofences, device binding, offline queue

Plan and assumptions: [hr-phase-2-plan.md](hr-phase-2-plan.md). API contract: [api/openapi.yaml](api/openapi.yaml)
(`/attendance/*`, `attendance.punch` sync event, `device_clock` on push).

## Built
| Area | What exists |
|---|---|
| Sites (**HR → Attendance sites**) | Mill / godown / procurement point with centre, radius and optional working hours (IST, days of week, windows across midnight for night shifts) |
| Notice and consent | Shown before the first punch, in plain words: location and a front-camera photo at punch time only, used only for attendance, seen only by HR, no tracking. Acceptance stored (append-only) per notice version; a new text asks everyone again |
| Device binding (**HR → Attendance devices**) | The phone asks; HR approves or rejects (with reason). One approved phone per employee: approving a new one retires the old. Nobody approves their own. Decisions audited |
| Punch | From the employee's own login (new `hr.attendance.punch`, given to every staff role and to a new minimal **Employee** role). GPS fix with accuracy, live front-camera photo (not from the gallery), device time and monotonic clock. Sent as the sync event `attendance.punch`: saved on the phone first, uploaded now or when back online, idempotent on the event id. The photo is uploaded first; a punch whose photo has not arrived waits instead of losing it |
| Verdict | Every punch is stored as it arrived (append-only) with **valid / flagged / rejected** and reasons in plain words, returned to the phone and shown to HR. Rejected: no employee record, employee not active, no consent, **phone clock changed**, future time. Flagged: unapproved phone, outside every geofence, outside the site's hours, poor GPS, no location, no photo, time not verifiable, two ins in a row, late upload |
| Time-tamper check | Every upload carries the device clock (boot / session id, monotonic ms, wall time). Within one boot the server works out when the punch really happened (`now − (monotonic now − monotonic at punch)`); more than 2 minutes from the phone's claimed time = clock changed → rejected, with both times kept as evidence |
| HR screens | **HR → Attendance**: every punch with verdict and reasons, filters (status, type, employee, IST day); punch page with phone time vs server-worked-out time, map link, accuracy, nearest site and distance, the photo (from private storage, never a public URL), the integrity signals |
| PWA | New **Attendance** card on the existing PWA (staff without gate rights see only this card): notice → I agree → ask HR to approve this phone → Punch in / Punch out; queued punches show "saved on this phone"; the server's verdict and reasons come back as messages. Works offline; service worker cache bumped |

## Sync engine changes (shared, kept backward-compatible)
* Handlers can opt into judging old events themselves (`AcceptsLateEvents`): attendance stores a punch older than the 72-hour offline limit as **flagged evidence** instead of sending it to the generic conflict queue.
* The punch carries its own `device_time`, because the generic event time is clamped for future skew, which would hide a future-dated punch.
* `POST /files` now also accepts the attendance photo (`hr.attendance.punch`).

## Tested
* **Server:** 22 new tests (with phase 1, 50 HR tests). Distances. Honest notice and consent. A valid punch end to end through the real API (consent, device request, HR approval, photo upload, push). Flags for site, phone, GPS and photo. The monotonic clock dating an offline punch, and a changed phone clock rejected. Unverifiable time flagged. Late uploads kept as evidence. Future time rejected despite clamping. Idempotency. Waiting for the photo. Sequence and hours. One phone per employee (no self-approval, replacement). Logins without an employee record or permission. Append-only at the database. Role grants. Screens: role access to 3 screens, site with hours, night windows across midnight, device approve / reject, verdicts in plain words and the evidence page, filters (IST day), and the employee tabs actually rendered.
* **PWA:** 2 new engine tests (offline punch with photo uploaded before the punch plus the device clock; verdict kept and notified). 17 in total.
* **In the browser** (dev server, test login `knl.worker` added to the demo credentials file): sign-in, Attendance card only (no gate screens), notice → I agree → consent stored, Ask HR → "Waiting for HR to approve this phone". Punching was not tried in the built-in browser, which has no GPS or camera.
* **Full suites:** PostgreSQL 539 passed, 1 skipped. MariaDB 528 passed, 12 skipped (PostgreSQL-only tests). Larastan, Pint and the OpenAPI contract test are clean.

## Fixed along the way
* **Salary history tab (phase 1) crashed when opened.** Filament fills closure parameters by name; the query hook was written as `fn (Builder $q)`, so Filament passed in an empty query builder. The earlier tests never rendered the lazily loaded tab. Now `$query`, and a test renders all three employee tabs.
* The same mistake in the new attendance list and day filter, caught by the screen tests.

## Next: phase 3
Fake / mock location and integrity checks (native signals, impossible travel speed, identical coordinates, accuracy outliers, one phone used for several employees, IP / network cross-check), the supervisor **review queue** for flagged punches (approve / reject with a reason, append-only decisions), and manual attendance with maker-checker.
