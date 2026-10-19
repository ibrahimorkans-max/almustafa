# Mill server: the whole ERP without internet

The ERP runs on a small server **inside the mill**. Every computer, the weighbridge PC and the phones use it
over the mill's own network (cable or Wi-Fi), so **every screen keeps working when the internet is down**:
gate, live weight, weighing, accounts, payroll, daily wages, reports, printing, the Assistant (commands).
Internet is used only when it is there: off-site backups, e-mail alerts, the AI mode of the Assistant,
updates, and the Owner reaching the ERP from outside.

```
 Office PCs ─┐
 Accountant ─┤  mill network (router / Wi-Fi)  ──  MILL SERVER  https://ricemill.local
 Phones ─────┤        no internet needed            (Ubuntu, PostgreSQL, the ERP, backups)
 Weighbridge PC (gate agent, scale, camera) ─┘
                                            └── internet when available: off-site backup, e-mail, remote access
```

## What works offline

| Works with no internet | Waits for the internet (nothing is lost) |
|---|---|
| Every screen: gate, weighbridge, live weight, lab, stock, production, sales, accounts, payments, payroll, HR, daily wages, approvals, reports, printing, the complete report | **Off-site backup copy**: the nightly backup is kept on the server (and the USB disk); the copy to the cloud is sent when the internet returns |
| Sign-in with password + 6-digit code (the code app works offline), passkeys | **E-mail alerts** and the backup anchor e-mail: written to the log while offline |
| Phone app on the mill Wi-Fi; when the Wi-Fi drops it keeps gate entries, attendance and leave on the phone and sends them later | **Assistant AI mode**: it switches to commands by itself |
| Gate agent, scale, camera, barrier | **Updates**: copied on a USB stick (see Updates) |

## 1. What to buy

| Item | Notes |
|---|---|
| Mini PC (server) | 4 cores, **8 GB RAM**, **256 GB SSD** or more, wired network port. Fanless types run well in dusty places |
| UPS | Keeps the server and the router running in a power cut, at least 30 minutes |
| External USB disk | Second copy of every backup, kept in the office safe or taken home weekly |
| Router / Wi-Fi access points | Give the server a **fixed IP address** (DHCP reservation), e.g. 192.168.1.10. Wi-Fi to reach the gate and the office |

## 2. Install the server (once, with internet if possible)

1. Install **Ubuntu Server 24.04 LTS** from a USB stick; create a user; enable OpenSSH when asked.
2. Give it the fixed IP in the router (DHCP reservation).
3. Copy this repository to it (`git clone https://github.com/ibrahimorkans-max/almustafa.git`) and run, as root:

   ```bash
   TLS_MODE=local DOMAIN=ricemill.local bash infra/vps/provision.sh
   ```

   It installs PostgreSQL, PHP, nginx, the queue worker and the scheduler, makes the **mill certificate** (the
   mill's own certificate authority, no internet needed), names the server **ricemill.local**, opens it to the
   mill network only, and keeps the clock right without internet.
4. Put the code in place (as the `ricemill` user):

   ```bash
   REPO=https://github.com/ibrahimorkans-max/almustafa.git bash infra/vps/deploy.sh main
   ```

   No internet at the mill? Build an update package elsewhere and use it instead (see Updates).
5. Create the first Owner: `cd /srv/ricemill/current/app && php artisan ricemill:create-owner owner`.
6. `php artisan ricemill:preflight` must show no FAIL lines.

## 3. Make each PC and phone trust the mill server (once per device)

The ERP is reached by HTTPS (needed for passkeys, the phone app and the camera). The mill's certificate has to be
installed once on each device:

* **Windows PC:** open `http://ricemill.local/mill-ca.crt` (or `http://192.168.1.10/mill-ca.crt`), open the file →
  *Install Certificate* → *Local Machine* → *Place all certificates in the following store* → **Trusted Root
  Certification Authorities** → Finish. Restart the browser.
* **Android:** open the same address in Chrome to download it; *Settings → Security → More security settings →
  Encryption & credentials → Install a certificate → CA certificate*, choose `mill-ca.crt`.
* **iPhone:** open the address in Safari, allow the profile; *Settings → General → VPN & Device Management* →
  install it; then *Settings → General → About → Certificate Trust Settings* → turn it on.

Then open **https://ricemill.local** (or https://192.168.1.10). If a phone does not find `ricemill.local`, use the
IP address.

## 4. Point the devices at the mill server

* **Browsers:** bookmark `https://ricemill.local/admin`.
* **Phone app:** open `https://ricemill.local/pwa/` and add it to the home screen again.
* **Weighbridge PC (gate agent):** in `agent.toml`, `[server] url = "https://ricemill.local"`. Install the mill
  certificate on that PC too (step 3).

## 5. Moving the data from the internet server (once)

If the ERP has been used on the internet server, bring its data to the mill server before people start using it
(and stop using the internet server from then on — two servers cannot both take entries):

```bash
# On the internet server (as root):
sudo -u postgres pg_dump --format=custom ricemill > ricemill.dump
# Copy ricemill.dump, /etc/ricemill/ricemill.env and /srv/ricemill/shared/storage/app to the mill server.
# On the mill server (as root), with the ERP stopped (supervisorctl stop all):
sudo -u postgres pg_restore --clean --if-exists --no-owner --role=ricemill -d ricemill ricemill.dump
```

Use the **same APP_KEY, RICEMILL_BLIND_INDEX_KEY and RICEMILL_BACKUP_KEY** as the internet server in
`/etc/ricemill/ricemill.env` (encrypted fields and old backups depend on them), keep `APP_URL=https://ricemill.local`,
copy the stored files into `/srv/ricemill/shared/storage/app`, then `supervisorctl start all` and run
`php artisan ricemill:preflight`.

## 6. Backups

* Every night the server makes an encrypted backup in `/srv/ricemill/shared/storage/backups` (kept 30 days).
* **Without internet** the off-site copy waits; every hour the server tries again and sends the missing copies
  as soon as the internet is back (`backup:offsite`).
* **USB disk:** plug it into the server and run `sudo bash /srv/ricemill/current/infra/vps/copy-backups-to-usb.sh`
  every week (or leave the disk plugged in: the script can run from cron). Keep the disk somewhere else.
* Keep a copy of `RICEMILL_BACKUP_KEY` off the server (sealed envelope with the Owner). Without it backups cannot
  be read.
* Every Sunday the server test-restores the latest backup into a separate database (`backup:verify`).

## 7. Updates without internet

On any computer with internet, Linux, PHP 8.3 and composer (the internet server works too):

```bash
bash infra/vps/make-offline-release.sh main      # makes ricemill-release-<commit>.tar.gz and .sha256
```

Copy both files to a USB stick, plug it into the mill server and run (as the `ricemill` user):

```bash
bash /srv/ricemill/current/infra/vps/deploy.sh /media/usb/ricemill-release-<commit>.tar.gz
```

The deploy checks the package, backs up first, migrates, checks the result and switches over; when the new version
does not answer it goes back to the old one by itself. With internet at the mill, `deploy.sh main` works as before.

## 8. Reaching the mill server from outside (optional, needs internet)

For the Owner at home: install **Tailscale** (free for small use) on the server and on the Owner's phone or laptop
(`curl -fsSL https://tailscale.com/install.sh | sh && tailscale up`). The ERP is then reachable at the server's
Tailscale address only by devices signed in to the mill's Tailscale account — nothing is opened to the whole
internet. When the internet is down, it simply waits; inside the mill everything goes on working.

## Checks

| Problem | Do this |
|---|---|
| "Your connection is not private" | The mill certificate is not installed on this device (step 3), or the address is not ricemill.local / the fixed IP |
| `ricemill.local` not found | Use the IP address; some phones and Windows versions do not resolve `.local` names |
| Server not reachable at all | Power / UPS, network cable, router; the server's fixed IP |
| Backups show "off-site: no" | Normal while offline; check again after the internet returns |
| Time looks wrong | `timedatectl` on the server; set it with `timedatectl set-time "2026-10-18 09:00"` if it ever drifts |
