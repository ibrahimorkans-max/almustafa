# HR module — Phase 2 plan: attendance punch flow, geofences, device binding, offline queue

Branch `hr-phase-2` (from `main` after merging access-security and HR phase 1).

## Data model (UUID keys, entity_id + row-level security)
| Table | Main columns | Notes |
|---|---|---|
| `sites` | code, name, kind (mill / godown / procurement_point / other), latitude, longitude, radius_m, time_windows (JSON: days + from / to, IST), status | geofences per entity |
| `employee_devices` | employee_id, device_id → `devices`, status (pending / approved / revoked), requested_at, decided_by / at, decision_note | one approved device per employee; a new device needs approval by HR |
| `attendance_consents` | employee_id, login_account_id, device_id, notice_version, accepted_at, ip | **append-only**; a punch needs consent to the current notice |
| `attendance_logs` | employee_id, kind (in / out), source (pwa / app / import / manual), occurred_at (device time), received_at, estimated_at (server reconstruction), time_check (verified / unverified / changed), lat / lng / accuracy, site_id + distance_m, selfie_sha256, device_id, login_account_id, client_event_id (unique per entity), status (valid / flagged / rejected), reasons, signals (raw client integrity signals, used in phase 3) | **append-only**; the supervisor's review (phase 3) is a separate append-only record |

## Flow
1. The employee signs in on their phone (PWA now; the native app uses the same API later). The **Attendance** card shows
   the notice ("location and a selfie are taken at punch time only, for attendance; no tracking") → **I agree** (consent stored).
2. The first punch from a device asks for **device approval**; HR approves it (Employees → Devices). Punches from an unapproved
   device are recorded but **flagged**, never silently accepted.
3. **Punch in / out**: GPS fix (accuracy), selfie from the camera, device time and a monotonic clock reading. Saved locally
   first (outbox, idempotency key = event id), uploaded now or when back online through the existing sync push
   (`attendance.punch`). The selfie is uploaded first through `POST /files` (content hash).
4. The server decides **valid / flagged / rejected** with reasons:
   * rejected: no employee record for the login, employee not active, no consent, device clock changed (verified), time in the future;
   * flagged: unapproved device, outside every geofence, outside the site's time window, poor GPS accuracy, time not verifiable
     (device restarted or app reloaded before syncing), missing selfie, two "in"s in a row, very late sync.
5. Time-tamper check: every push carries the device's clock reading (`device_clock`: boot / page id, monotonic ms, wall time). For
   punches from the same boot, the server reconstructs the real punch time as *now − (monotonic now − monotonic at punch)*;
   more than 2 minutes away from the claimed device time = clock changed.

## Assumptions
1. **No native app exists yet** (the Flutter app is a placeholder from the ERP plan). Phase 2 ships the server, the API contract
   (OpenAPI) and the PWA punch flow. The native app later sends the same event, plus the integrity signals (mock location, root,
   Play Integrity / App Attest) that phase 3 evaluates. A browser cannot see mock-location flags, so PWA punches carry none,
   and phase 3 will treat that as "unknown", not as "clean".
2. Employees punch with **their own login** (permission `hr.attendance.punch`, granted to all staff roles and a new minimal
   **Employee** role for staff who only punch). One employee, one approved device.
3. Biometric / gate-PC imports are left for phase 4 (with shifts), where in and out pairs become worked hours.
4. Viewing attendance needs `hr.employee.view`; approving devices needs `hr.employee.edit`; manual attendance and the
   review queue follow in phase 3 (`hr.attendance.override`, maker-checker).
5. Selfies are stored in the private content-addressed store (as weighbridge photos) and shown only to HR viewers.
   Liveness checks come with the native app (phase 3); the PWA takes a live camera frame, not a gallery upload.
