# MillNexa Weighbridge & Gate Agent — User Manual

14 November 2026

## About this manual

The gate agent is a small program on the weighbridge PC. It reads the weighbridge indicator and sends the weight to the ERP. People never type weights: the ERP shows what the scale reads, live, about 10 readings a second.

This manual covers the whole job, from a new PC to daily weighing and fixing problems.

| Part | What it is |
| --- | --- |
| ERP (MillNexa) | The website the mill uses. It shows live weight, records weighments and holds the users. |
| Weighbridge PC | The Windows PC next to the scale. The gate agent runs on it, in the folder `C:\RiceMillAgent`. |
| Indicator | The weighing display box. It connects to the PC by a serial (COM) cable or by network. |
| Camera (optional) | A USB or IP camera that photographs each truck when it is weighed. |
| PC login (`wb.<code>`) | The PC's own ERP login, made by pairing. Only the agent uses it. Nobody knows its password. |

| Who | What they do |
| --- | --- |
| Owner or Super Admin | Pairs the PC, creates users, approves new devices. |
| Gate / weighbridge operator | Signs in with their own login and records weighments. |
| Server admin | Uses the server commands in this manual when the ERP cannot fix a login. |

## Before you start

Have these ready before you set up a weighbridge PC:

- [ ] A Windows 10 or 11 PC with internet.
- [ ] **Python 3.12** from python.org. During install, tick **Add python.exe to PATH**.
- [ ] The indicator cable plugged into the PC (a serial or USB-serial cable), with the indicator switched on.
- [ ] The agent folder: unzip **RiceMillAgent.zip** to `C:\RiceMillAgent`.
- [ ] The ERP address exactly as you open it in the browser.
- [ ] An **Owner or Super Admin** signed in to the ERP on another computer or phone, to make the pairing code.
- [ ] Optional, for cameras: **ffmpeg**. Install it in PowerShell with `winget install Gyan.FFmpeg`.

**Opening PowerShell as administrator:** click Start, type `powershell`, right-click **Windows PowerShell** and choose **Run as administrator**. Then go to the agent folder with `cd C:\RiceMillAgent`. Every command in this manual is typed there.

## Part 1 – Register (pair) the weighbridge PC

Pairing connects the PC to one weighbridge in the ERP with a one-time code. Nothing secret is typed by hand.

**In the ERP (Owner or Super Admin):**

1. Open **Security → Devices → Pair a weighbridge PC**.
2. Choose the weighbridge.
3. Leave **PC login empty**. The ERP then makes the PC's own login, named `wb.<weighbridge code>` (for example `wb.md001.6`).
4. Press **Make pairing code**. You get a code like `ABCD-EF23`. It works **once**, for **30 minutes**.

**On the weighbridge PC:**

1. Open PowerShell (administrator is not needed for this step) in `C:\RiceMillAgent`.
2. Run: `powershell -ExecutionPolicy Bypass -File .\setup-windows.ps1`
3. Type the ERP address and the pairing code when asked.
4. The script installs the agent, pairs the PC and tests the sign-in. It then looks for the indicator and runs `check` (Part 2).

**About the PC login `wb.<code>`:**

- Its password is random and stored only on this PC, locked to this PC with Windows protection (DPAPI). A copied `agent.toml` is useless on another PC.
- It does **not** count towards the user limit of your plan.
- Nobody can sign in with it in the browser or the app. A person who tries gets the message *"This is the weighbridge PC's own login…"*. That attempt does not lock anything.
- Creating new users later does **not** affect the PC's connection.

**Pairing again** (new PC, reinstall, or the PC's login was reset): make a new code in the ERP and run `.venv\Scripts\ricemill-agent --config agent.toml pair`. Choose the existing `wb.…` login as PC login, or leave it empty. The indicator and camera settings stay. The old password and device stop working.

If the ERP refuses to make a code, it shows **No code made** with the reason. Choosing a person's login as PC login is refused: leave PC login empty instead.

## Part 2 – Connect the indicator and camera

`setup-windows.ps1` does these steps by itself. Run them by hand when something changes, such as a new cable or a new camera. In the commands below, `ricemill-agent` means `.venv\Scripts\ricemill-agent`.

**Indicator**

1. List the COM ports: `ricemill-agent ports`.
2. Put a weight on the scale, or press on the platform, so the indicator sends changing numbers.
3. Find the port, speed and format, and save them: `ricemill-agent --config agent.toml detect --write`.
4. Compare with the display for 10 seconds: `ricemill-agent --config agent.toml watch --seconds 10`. The numbers must match the indicator.

A network indicator, or an RS-232-to-Ethernet converter, is set by hand in the `[indicator]` section of `agent.toml`: `type = "tcp"`, `host` and `port`. Most indicators (such as PF-7E) use 9600 baud. Yaohua-style output like `=0.84520` uses `format = "reversed"`.

**Camera (optional)**

1. List USB cameras: `ricemill-agent cameras` (needs ffmpeg).
2. In the `[camera]` section of `agent.toml`, set `type` and either `device` (USB camera name) or `url` (IP camera or DVR RTSP address, or a snapshot address).
3. Take one test photo: `ricemill-agent --config agent.toml camera-test`. It saves `camera-test.jpg`.

A camera that fails never stops weighing. The weighment is recorded and flagged "no photo".

**Test everything**

Run `ricemill-agent --config agent.toml check`. It tests every step from the indicator to the ERP's Live weight screen and says what to fix. Go on to Part 3 only when `check` passes.

## Part 3 – Start by itself when the PC starts

After this step the agent runs whenever the PC is on, even when nobody logs in to Windows. Do it once, after `check` passes.

1. Close any agent window you started by hand, so only one copy sends.
2. Open PowerShell **as administrator** in `C:\RiceMillAgent`.
3. Run: `powershell -ExecutionPolicy Bypass -File .\install-startup.ps1`
4. It says **Done. The agent now starts by itself…** and shows the first lines of `live.log`.
5. Check the ERP's Live weight screen shows the weight.
6. Restart the PC. Without logging in, look at Live weight from another computer. The weight comes back within about a minute.

The script adds two Windows tasks. See them in **Task Scheduler → Task Scheduler Library**.

| Task | When it runs | What it does | Its log |
| --- | --- | --- | --- |
| RiceMill Agent - live weight | At PC start-up; restarted every minute if it stops | Sends the indicator reading to the ERP screens | `live.log` |
| RiceMill Agent - sync | Every minute, and at start-up | Sends recorded weighments and photos; catches up after the internet returns | `sync.log` |

Both logs are in `C:\RiceMillAgent` and start afresh each run.

**Turn it off:** run the same script with `-Remove` (PowerShell as administrator):

```powershell
powershell -ExecutionPolicy Bypass -File .\install-startup.ps1 -Remove
```

**After updating the agent:** unzip the new version over `C:\RiceMillAgent` (`agent.toml` is kept), then run `install-startup.ps1` again. It replaces the old tasks and starts the new version.

## Part 4 – People who use the weighbridge

Every operator signs in with **their own login**, never with the PC's `wb.…` login.

**Create an operator (Owner or Super Admin):**

1. Open **Administration → Users → New**.
2. Fill in the person and choose the role **Weighbridge / Gate Operator**.
3. Give them their username and first password privately.

**First sign-in on a new phone or computer:**

Depending on **Security → Sign-in rules → New phones and computers**, a device used for the first time may need approval:

| Setting | What happens |
| --- | --- |
| Everyone | Every new device waits for approval before it can sign in. |
| Selected accounts only | Only accounts with **Only from approved devices** switched on (under Users) wait. |
| Off | Any device with the right password signs in. Not recommended once the mill is live. |

When a device waits, the person sees that it needs approval. An Owner or Super Admin approves it in **Administration → Approvals**. An Owner's own new device must be approved by **another** Owner. If you are the only Owner, the server admin can approve it (see *For the server admin*).

**Locked logins:** five wrong passwords lock a login for a while. An Owner or Super Admin unlocks it in **Administration → Users**, with the **Unlock** action on that user. **Security → Failed sign-ins** shows each refused sign-in and the real reason.

## Part 5 – Daily work

Once Part 3 is done, nobody needs to touch the weighbridge PC. Leave it switched on.

**Live weight:** open **Live weight** in the ERP. It shows the scale reading about 10 times a second, a short moment behind the indicator. **Stable** shows when the last readings agree within one scale step. **No signal** means the agent is not sending (see Troubleshooting).

**Recording a weighment:**

1. The truck arrives and its gate entry is made (registration number, sauda, bags).
2. The truck drives on the scale. The weight is taken only when it is stable. A photo is taken at the same moment.
3. First weighing is **gross** (loaded) or **tare** (empty), depending on the trip; the second weighing completes the slip.
4. Weights are never typed. The server checks the readings and flags anything unusual, such as unstable weight, no photo, an expired scale certificate or a tare far from the truck's usual tare.

**When the internet is down:** the agent keeps weighments in an encrypted store on the PC. The sync task sends them within a minute of the internet coming back, in the right order. Live weight on the screens pauses until then.

**Barrier and number plate (if fitted):** the barrier opens only when the ERP says yes: a registered arrival going in, a slip or gate pass going out. Offline, on an error or with a dead relay it stays **closed**. A guard can open it by hand with a written reason; that truck is flagged in the ERP. The plate is sent exactly as the camera read it, and the ERP flags a mismatch.

## Command reference

Type these in PowerShell in `C:\RiceMillAgent`. Write each as `.venv\Scripts\ricemill-agent --config agent.toml <command>`; commands marked \* need no `--config`.

| Command | What it does |
| --- | --- |
| `pair` | Pairs the PC with a new code from the ERP. Keeps indicator and camera settings. |
| `login` | Type the PC login's password again; saved only after the ERP accepts it. |
| `check` | Tests every step from the indicator to the Live weight screen and says what to fix. Also retries a refused password at once. |
| `status` | Shows what is waiting to be sent. |
| `live` | Sends live weight to the screens (the start-up task runs this). |
| `live --simulate 24560 --count 10` | Sends a pretend truck of 24,560 kg, for testing without a scale. |
| `sync` | Sends recorded weighments and photos now (the sync task runs this every minute). |
| `ports` \* | Lists the PC's COM ports. |
| `detect --write` | Finds the indicator's port, speed and format and saves them. `--port COM3` checks one port only. |
| `watch --seconds 10` | Shows readings as the agent reads them. |
| `cameras` \* | Lists USB cameras (needs ffmpeg). |
| `camera-test` | Takes one photo into `camera-test.jpg`. |
| `arrive <reg no> --sauda <id> --bags <n>` | Records a truck arrival from the PC. |
| `capture <gate entry id> gross` or `tare` | Takes a stable weight and photo for that entry. |
| `gate in --plate <reg no>` | Plate read and barrier decision. |
| `manual-open in "<reason>"` | Guard opens the barrier by hand; the truck is flagged. |
| `simulate <reg no> <gross> <tare>` | Whole weighing with the simulator, for testing. |
| `protect` | Encrypts a secret for `agent.toml` (only for setup by hand). |
| `new-store-key` | Makes a new store key (only for setup by hand). |

**Scripts:** `setup-windows.ps1` installs and pairs; `install-startup.ps1` adds the start-up tasks (`-Remove` takes them away).

## Troubleshooting

Start with `check` on the PC and **Security → Failed sign-ins** in the ERP. Together they show most problems.

| What you see | Why | What to do |
| --- | --- | --- |
| Live weight shows **kg, No signal** | The agent is not running, cannot reach the ERP, or cannot read the indicator. | On the PC run `check` and fix what it says. Look at `live.log`. Make sure the start-up task is installed (Part 3). |
| The weight shown does not match the indicator | Wrong port, speed or format. | Run `detect --write`, then `watch --seconds 10`. |
| Weight moves only now and then, not smoothly | Old agent version, or a slow internet line. | Install the newest agent zip and run `install-startup.ps1` again. |
| *"These credentials do not match our records, or the account is locked"* | Wrong password, or five failures locked the login. | Owner or Super Admin: **Administration → Users → Unlock**. The only Owner locked out: server admin runs `auth:unlock`. |
| *"This is the weighbridge PC's own login…"* | Someone typed the PC's `wb.…` login. | Sign in with your own login. No login yet: Owner creates one (Part 4). Nothing was locked. |
| Device waits for approval, but nothing in **Approvals** | The request is in the mill of that login, or it is an Owner's device. | Another Owner approves it, or the server admin runs `auth:devices <user> --trust`. |
| Agent's `live.log` says the ERP refused the password | The PC login was locked or reset. | Unlock `wb.…` in the ERP; the agent retries by itself within 30 minutes, or run `check` to retry now. Login was reset: pair again (Part 1). |
| Agent says the device was revoked or access disabled | The PC was removed in **Security → Devices**. | Pair again with a new code (Part 1). |
| Pairing: **No code made** | The chosen PC login is a person's login, or the weighbridge is not set up. | Leave PC login empty, and read the reason shown. |
| `install-startup.ps1`: *Open PowerShell as administrator* | PowerShell was not run as administrator. | Right-click Windows PowerShell → Run as administrator. |
| `install-startup.ps1`: *Run setup-windows.ps1 first* | The agent is not installed or paired in this folder. | Run `setup-windows.ps1` (Part 1). |

**Why the agent waits 30 minutes:** every computer at the mill shares one internet address. If the agent kept trying a wrong password every minute, the ERP would lock that login for everyone there. Trying every 30 minutes is too rare to cause a lock.

## For the server admin

Use these only when nobody in the ERP can fix the problem, for example when the only Owner is locked out. Sign in to the server over SSH, then go to the app folder first:

```bash
cd /srv/ricemill/current/app
```

Run every command as the app user, as `sudo -u ricemill php artisan <command>`. (*Could not open input file: artisan* means you are not in that folder.)

| Command | What it does |
| --- | --- |
| `auth:unlock <username>` | Unlocks a login and clears the address locks of its sign-ins. |
| `auth:unlock <username> --reset-password` | Also sets a temporary password. It is written to `storage/app/private/unlock-<username>.txt`, never printed. Give it to the person privately, then delete the file. |
| `auth:sign-ins <username>` | The last sign-ins of that login, with the real reason each refused one failed. `--failed` shows only failures; `--limit=50` shows more. Without a username: all logins. |
| `auth:devices <username>` | That login's devices, which wait for approval, and where the approval request is. |
| `auth:devices <username> --trust` | Approves that login's newest waiting device and withdraws its pending request. |

Example: `sudo -u ricemill php artisan auth:sign-ins wb.md001.6 --failed`.

Never use `--reset-password` on the PC's `wb.…` login: the agent cannot know the new password. Unlock it without that option, or pair the PC again.

## Security rules and good practice

- One person, one login. Never share a login, and never use the PC's `wb.…` login for a person.
- Never copy `agent.toml` to another PC or send it to anyone. It holds the PC's secrets (they only work on this PC, but treat them as private).
- Keep the agent folder at `C:\RiceMillAgent` and let only administrators change it.
- Run only **one** copy of the agent on a PC: the start-up tasks, or a window started by hand, not both.
- When a weighbridge PC is replaced or lost, remove it in **Security → Devices** and pair the new PC with a new code.
- Temporary passwords from the server go into a private file. Pass them on in person and delete the file afterwards.
- Keep **Sign-in rules → New phones and computers** on *Everyone* or *Selected accounts* once the mill is live.
- Have at least two Owners, so one Owner can always approve the other's new device.
