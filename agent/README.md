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
| `drivers/anpr.py` | Number-plate camera (HTTP result + snapshot); **simulator** (can misread a character on purpose) |
| `drivers/barrier.py` | Boom barrier via network relay (TCP text command); **simulator** (can simulate a dead relay) |
| `drivers/display.py` | Two-line LED board (TCP text); **simulator** |
| `live.py` | Live weight for the screens: the indicator reading about once a second to `POST /gate/live-weight`, marked stable when the last readings agree within one step; display only, never queued |
| `gate_control.py` | Plate read → queued `gate_entry.plate_read`; asks the server `POST /gate/barrier`; opens only on yes; **fails closed** offline; guard override queued as `barrier.manual_open` |

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
4. Protect the three secrets with Windows DPAPI instead of pasting them in plain text: run
   `.venv\Scripts\ricemill-agent protect` once per secret (it asks for the value without showing it) and paste the
   printed `dpapi:…` value into `password`, `signing_secret` and `[store].key`. Only this PC can decrypt them, so a copied
   `agent.toml` or disk image is useless elsewhere. `--scope user` binds them to the Windows account instead (run it as
   the service account). The `RICEMILL_AGENT_*` environment variables still work and take precedence.

## Use

```powershell
ricemill-agent --config agent.toml arrive MNA211234 --sauda <sauda-id> --bags 400
ricemill-agent --config agent.toml capture <gate-entry-id> gross
ricemill-agent --config agent.toml sync
ricemill-agent --config agent.toml status
ricemill-agent --config agent.toml simulate MNA211234 24560 9870     # simulator end-to-end
ricemill-agent --config agent.toml gate in --plate MNA211234           # plate read + barrier decision
ricemill-agent --config agent.toml manual-open out "Server down, ambulance behind"   # guard override (flagged)
ricemill-agent --config agent.toml live                                # live weight on the screens (run as a service)
ricemill-agent --config agent.toml live --simulate 24560 --count 10    # simulator
```

Run `sync` from Task Scheduler every minute (or as a service); everything else works without network.

## Behaviour that matters
* No typed weights. A weight is recorded only after a stable window; the server re-checks the evidence and flags
  `stability_unverified`, `no_photo`, `scale_certificate_invalid`, `tare_deviation`.
* Camera failure never blocks weighing (the weighment is flagged instead).
* An event is removed from the queue only after a final server answer; `retry` (e.g. the gate entry was made on
  another device that has not synced yet) keeps it.
* A remote-wipe instruction erases the local store, then acknowledges.
* The barrier opens only on the server's yes (registered arrival in; slip out; gate pass for a dispatch). Offline,
  on an error or with a dead relay it stays closed and the board says so. The guard's manual open needs a reason and
  flags the vehicle on the server.
* The plate read is forwarded exactly as the camera saw it; the server compares and flags `plate_mismatch`.

## Tests

```powershell
.venv\Scripts\python -m unittest discover -s tests -v
```

The signing test shares a fixed vector with `app/tests/Unit/AgentSignatureVectorTest.php`, so the PHP and
Python implementations cannot drift apart.
