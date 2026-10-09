# Runbook: lockdown, duress and lost devices

Keep this printed next to the owner's and the Super Admin's desk. Every step below is recorded in the audit log.

## 1. Emergency lockdown
**When:** suspected break-in to the server or a PC, a leaked password of an owner or accountant, staff misuse in progress.

1. Sign in as Super Admin (2FA) → **Administration → Security centre → Emergency lockdown**.
2. Choose **Whole system** or the affected **entity**, write the reason, enter the current 2FA code.
3. Result: everyone except Super Admins is signed out on every device and cannot sign in ("System locked by administrator").
   Gate agents show SYSTEM LOCKED and keep the barrier closed; weighments taken meanwhile stay queued on the gate PC.
   Scheduled work for the locked scope (anomaly scan, deal expiry, reminders, archiving) is skipped; backups continue.
   Owners get an e-mail and an in-app message.
4. Investigate: **Audit log**, **Sessions**, **Devices**, **Access review**. Change passwords / reset 2FA of affected
   accounts, revoke devices, suspend accounts.
5. **Lift lockdown** on the same page: say why it is safe, enter a new 2FA code. Owners are told; people sign in again;
   gate agents sync their queue at the next run.

## 2. Duress PIN used
**What the person sees:** "Signed out. Please try again later." Nothing else changes on their screen.
**What the system did:** alerted owners, Super Admins and the security contacts (time, IP, device); flagged the account
(Users → *Duress alert* column); ended every session; suspended the account and queued a wipe of the device if the
person chose so on their *Duress PIN* page.

1. **Do not call back through the same device.** Phone the person on their registered number, or go in person.
   If they cannot speak freely, call the police if needed. Do not reveal the alert to anyone else.
2. Consider an **entity lockdown** if money or stock could be moved.
3. When the person is safe, re-verify their identity (in person, or by a question only they can answer).
4. Have them change their password (Users → *Reset password* gives a temporary one) and, if the phone was taken,
   **Mark lost** on that device.
5. Users → **Clear duress alert** with a note of how the person was reached and verified; **Reinstate** if suspended.
6. Review the audit log for anything done with the account shortly before the alert.

## 3. Lost or stolen phone, tablet or gate PC
1. Users → the person → note the device; **Devices → Mark lost / stolen**. Write what happened. Keep *Also sign this
   user out on all their other devices* on, unless you are sure only the device is at risk.
2. Result: at its next connection the device erases its encrypted local data and acknowledges; until then every
   request from it is refused (`wipe_required`), so nothing can be read through it. A browser device is revoked.
   If the device never connects again, its tokens expire within 24 hours and the app locks itself after 72 hours offline;
   local data stays encrypted.
3. Reset the person's password and, if the device held a 2FA app, reset 2FA.
4. A gate PC: register a replacement agent (Devices → Register gate agent) and protect its secrets with
   `ricemill-agent protect`.

## 4. Account locked after failed sign-ins
* 3 wrong passwords → a growing pause of a few seconds; 5 → locked for 15 minutes; owners are told.
* 3 lockouts within 24 hours → stays locked until an administrator unlocks it (Users → **Unlock**). Before unlocking,
  call the person: if they did not try to sign in, treat it as an attack (reset password, check **Access review**,
  consider a lockdown).

## 5. Maintenance window
Security centre → **Schedule maintenance**: message, start, expected end. Your browser keeps working; the bypass link
shown once works on other devices — keep it private. Everyone else sees the message and the end time (API: 503 with
Retry-After); phones, PWA and gate agents keep recording and sync afterwards. **End maintenance now** when done.
