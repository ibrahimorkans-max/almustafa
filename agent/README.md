# Gate agent (Python)

Runs on the weighbridge PC. It is the only component that talks to gate hardware, works fully offline
(encrypted local queue) and uses the same sync API as the PWA and the Flutter app, with **signed requests**.

| Module | What |
|---|---|
| `drivers/indicator.py` | Weighbridge indicator over RS-232 (`pyserial`) or TCP; configurable frame regex; **simulator** |
| `drivers/camera.py` | IP camera snapshot over HTTP; **simulator** (unique PNG per shot) |
| `stability.py` | Stable-weight detection: N consecutive samples within one scale step; samples are sent as evidence |
| `gate.py` | Arrival + weight capture: wait for stable weight → photo → SHA-256 → queue `weighment.captured` |
| `queue.py` | SQLite store, every row Fernet-encrypted: outbox, photos, cached feed; remote wipe |
| `api.py` / `signing.py` | HTTPS client (stdlib), device-bound tokens, refresh, HMAC request signing |
| `sync.py` | Upload photos → push events in order → pull feed; keeps `retry` / `pending_online` events |

ANPR, boom barrier and display board drivers arrive in phase 6 on the same pattern (interface + simulator).

## Setup (Windows weighbridge PC)

```powershell
py -3.12 -m venv .venv
.venv\Scripts\pip install -e .[serial]
.venv\Scripts\ricemill-agent new-store-key          # → [store].key or RICEMILL_AGENT_STORE_KEY
copy agent.example.toml agent.toml                   # fill in; restrict file access to the service account
```

1. In the admin panel create a **GATE_OPERATOR login account** for this PC (e.g. `wb.agent`).
2. Devices → **Register gate agent** → copy the `device_uid` and `signing_secret` (shown **once**).
3. Weighbridges → copy the scale id into `[scale].id`.

## Use

```powershell
ricemill-agent --config agent.toml arrive HR05AB1234 --sauda <sauda-id> --bags 400
ricemill-agent --config agent.toml capture <gate-entry-id> gross
ricemill-agent --config agent.toml sync
ricemill-agent --config agent.toml status
ricemill-agent --config agent.toml simulate HR05AB1234 24560 9870     # simulator end-to-end
```

Run `sync` from Task Scheduler every minute (or as a service); everything else works without network.

## Behaviour that matters
* No typed weights. A weight is recorded only after a stable window; the server re-checks the evidence and flags
  `stability_unverified`, `no_photo`, `scale_certificate_invalid`, `tare_deviation`.
* Camera failure never blocks weighing (the weighment is flagged instead).
* An event is removed from the queue only after a final server answer; `retry` (e.g. the gate entry was made on
  another device that has not synced yet) keeps it.
* A remote-wipe instruction erases the local store, then acknowledges.

## Tests

```powershell
.venv\Scripts\python -m unittest discover -s tests -v
```

The signing test shares a fixed vector with `app/tests/Unit/AgentSignatureVectorTest.php`, so the PHP and
Python implementations cannot drift apart.
