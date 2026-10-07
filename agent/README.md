# Gate hardware agent (Python) — skeleton

Built in **phase 3** (weighbridge, lab) and **phase 6** (ANPR, boom barrier, display board).

Planned shape:

```
agent/
  ricemill_agent/
    drivers/        weighbridge (RS-232/TCP), anpr, boom (relay/IO + loop/IR), display — each with a simulator
    queue/          encrypted local SQLite event queue (offline-first)
    sync/           client for the Laravel sync API (same contract as PWA/Flutter), signed requests
    flows/          inbound paddy / outbound rice state machines, exit check, fail-safe + manual bypass (logged)
  tests/
```

Authentication: the agent is a `device` (platform `agent`) bound to a dedicated login account; it uses the same
`/api/v1/auth/*` token flow (device-bound, rotating refresh tokens). Request signing and the expected-vehicle cache
are specified with the sync contract in phase 2.

Python 3.12+ is required (not installed on the phase-1 build machine).
