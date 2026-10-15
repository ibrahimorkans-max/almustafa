# Connecting a weighbridge to the ERP

The weighbridge indicator (the box showing the weight) sends its reading to a small program, the
**gate agent**, on a Windows PC at the weighbridge. The agent sends the reading to the ERP about once a
second (the **Live weight** screen) and records weights with their evidence. The ERP never talks to
the indicator directly.

```
Load cells → Indicator ──RS-232 cable or network──► Weighbridge PC (gate agent) ──internet (HTTPS)──► ERP
```

## 1. What you need

| Item | Notes |
|---|---|
| Indicator with a data output | RS-232 (9-pin DB9) is the most common; some have Ethernet. The output must be set to **continuous** |
| Windows 10/11 PC at the weighbridge | Always on, on the internet; a UPS is strongly advised |
| Cable | RS-232 cable to the PC (straight or null-modem: the indicator manual says which), at most about 15 m. Longer: an RS-232-to-Ethernet converter |
| USB-to-RS232 adapter | Only if the PC has no COM port. Prefer FTDI-chip adapters |
| Valid stamping certificate | Weights on an unstamped scale are flagged |

## 2. Set up the indicator

In the indicator's settings menu (see its manual, or ask the scale company's engineer):

1. **Serial output: continuous** (sometimes "stream" or "cont"), not "print on key".
2. **Baud rate** 9600 (or note what it is), **8 data bits, no parity, 1 stop bit** (8N1).
3. Unit **kg**. Note the **division** (the smallest step, often 10 or 20 kg).

## 3. Connect and check the data reaches the PC

1. Plug the cable into the indicator and the PC (or the USB adapter).
2. **Device Manager → Ports (COM & LPT)**: note the port, e.g. `COM3`.
3. Open PuTTY (free), choose **Serial**, enter the port and baud rate, and open it. Lines such as
   `ST,GS,+0025480kg` or `=  25480 kg` should scroll past about every second.
   * Nothing at all: wrong port, wrong cable type (try a null-modem adapter), or output not continuous.
   * Strange characters: the baud rate does not match the indicator.
4. Close PuTTY (only one program can use the port at a time).

## 4. Prepare the ERP (Owner or the mill's Super Admin)

1. **Gate & weighbridge → Weighbridges → New**: code (e.g. `WB1`), name, capacity in kg, and the
   stamping certificate with its dates. Open it and copy its **id**.
2. **Administration → Users → New**: a login for the PC only, role **Weighbridge / Gate Operator**,
   username e.g. `wb.agent`. Sign in with it once to set its own password.
3. **Security → Devices → Register gate agent**: choose that login. Copy the `device_uid` and the
   `signing_secret`. **The secret is shown only once**; keep it until step 5 is done.

## 5. Install the gate agent on the weighbridge PC

Install Python 3.12 (python.org, tick *Add to PATH*), copy the `agent` folder to e.g. `C:\RiceMillAgent`, then
in PowerShell in that folder:

```powershell
py -3.12 -m venv .venv
.venv\Scripts\pip install -e .[serial]
.venv\Scripts\ricemill-agent new-store-key
copy agent.example.toml agent.toml
```

Edit `agent.toml`:

```toml
[server]
url = "https://almustafarm.zhtrader.com"

[auth]
username = "wb.agent"
password = "dpapi:…"          # from: .venv\Scripts\ricemill-agent protect
device_uid = "agent-…"         # from step 4.3
signing_secret = "dpapi:…"     # from step 4.3, protected the same way

[store]
key = "dpapi:…"                # the new-store-key value, protected

[scale]
id = "…"                       # the weighbridge id from step 4.1
resolution_kg = "10"           # the indicator's division
min_stable_samples = 6

[indicator]
type = "serial"
port = "COM3"
baudrate = 9600
# frame_pattern = '(?P<sign>[+-])?\s*0*(?P<weight>\d+)\s*(?P<unit>kg)'   # only if the default does not read your indicator
```

Run `.venv\Scripts\ricemill-agent protect` once for each secret (password, signing secret, store key);
it asks for the value without showing it and prints the `dpapi:…` text to paste. Only this PC can
decrypt them.

An indicator on the network instead of a cable: `type = "tcp"`, `host = "192.168.1.50"`, `port = 4001`.

## 6. Test

1. `.venv\Scripts\ricemill-agent --config agent.toml sync` must finish without an error: the PC reaches the
   ERP and the login, device and secret are right.
2. `.venv\Scripts\ricemill-agent --config agent.toml live` and open **Gate & weighbridge → Live weight**
   in the ERP. The number must match the indicator display within a second, and show **Stable** when
   the weight settles and **Moving** while it changes.
3. Put a known test weight (or a truck weighed elsewhere) on the platform and compare.
4. Without a scale: `ricemill-agent --config agent.toml live --simulate 24560 --count 10` sends a
   pretend truck, to test the screens.

## 7. Keep it running

In **Task Scheduler** create two tasks running as the PC's service account, *whether the user is logged
on or not*:

* **At startup**, restart on failure every minute:
  `C:\RiceMillAgent\.venv\Scripts\ricemill-agent.exe --config C:\RiceMillAgent\agent.toml live`
* **Every minute**: `… --config C:\RiceMillAgent\agent.toml sync` (sends recorded weights; works offline
  and catches up later).

## 8. Recording and checking weights

* **At the scale:** on **Live weight**, choose the vehicle on the platform from *Vehicles waiting* and press
  **Record gross** or **Record tare** once the reading shows **Stable**. The button stays disabled while the
  weight moves or there is no signal. The readings of the last seconds are kept as evidence. The agent's own
  `capture` command (with the camera photo) also still works.
* **Checking:** the mill's Super Admin (or the Owner) opens **Gate & weighbridge → Weight checks**, looks at each
  new weight with its evidence and flags, and marks it **OK** or **Query** with a note. Nobody checks a weight they
  recorded. Unchecked weights stay on the list.

## Problems and what to do

| The screen shows | Do this |
|---|---|
| **No signal** | Is the agent running (`live`)? Is the PC on the internet? Is the cable in? Is the indicator on? |
| Numbers but wrong (×1000, missing digits) | The frame pattern does not suit the indicator; set `frame_pattern` (the scale engineer can tell the frame format) |
| Always **Moving** | `resolution_kg` smaller than the indicator's division, or a wobbling platform; set the division, check the load cells |
| "The reading is outside the weighbridge range" | The capacity under Weighbridges is wrong, or the frame is misread |
| Weights flagged *scale_certificate_invalid* | Enter the current stamping certificate under Weighbridges |
| "This device is new to your account" for the agent | Approve the agent's device under Approvals, or register it again under Devices |
