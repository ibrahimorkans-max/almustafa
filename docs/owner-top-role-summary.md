# Owner as the top role, screen protection, page-expiry fix

## Owner and Super Admin

| | Owner (`OWNER`) | Super Admin (`SUPER_ADMIN`) |
|---|---|---|
| Scope | Every mill (global role, `entity_id` NULL) | One mill |
| Rights | Every permission, including `entity.manage` (create, suspend and edit firms) | Every permission inside the mill except `entity.manage`; edits its own mill with `entity.edit_own` |
| Only an Owner can | Lock or unlock the system, schedule maintenance, request a manual weight, change the Owner role, manage Owner accounts and devices, work in "All firms" mode | — |
| 2FA | Required | Required |
| Session limit | 12 hours (was 60 minutes for the global role) | 12 hours |

Migration `2026_10_29_000100_make_owner_the_top_role` swaps the existing rows. The global role
(`SUPER_ADMIN` "Super Admin") becomes `OWNER` "Owner", and every mill's `OWNER` "Owner" role becomes
`SUPER_ADMIN` "Super Admin". People keep their accounts: whoever held the global role is now the Owner,
and each mill's former Owner account is now that mill's Super Admin. Usernames do not change.

In code, `LoginAccount::isOwner()` (was `isSuperAdmin()`) means the global top role. The column
`roles.is_super_admin` and the `RICEMILL_SA_*` / `super_admin_*` settings keep their names so existing
servers and the API (`role.is_super_admin`) need no changes; they now apply to the Owner.

The bootstrap command is `php artisan ricemill:create-owner <username>`; the old name
`ricemill:create-super-admin` is kept as an alias. Demo data creates the global account as `owner`.

## Screenshot and recording protection

`public/js/screen-guard.js`, loaded for every signed-in user (`RICEMILL_SCREEN_GUARD`, default on):

* a faint repeating watermark with the username, IP and time on every screen (it comes back if removed);
* the page is hidden while the browser window is not in focus, which is what the Snipping Tool,
  the Game Bar recorder and most capture programs cause;
* Print Screen hides the page and empties the clipboard; Ctrl+P and the Mac capture keys are blocked;
* printing and "Save as PDF" give an empty page; text outside form fields cannot be selected.

The live weight screen keeps the watermark but is not hidden, so a gate monitor stays readable.

**Switching it on and off: Security → Screen protection.** The Owner sets it for all mills: *On*,
*Off*, or *Compulsory* (on in every mill, and no mill can turn it off). Each mill's Super Admin sets
it for that mill: *Same as all mills*, *On* or *Off* (not *Off* while the Owner made it compulsory).
Nobody else sees the page. Changes are audited (`security.screen_guard_changed`) and apply from the next
page opened. `RICEMILL_SCREEN_GUARD` only gives the starting value until the Owner saves a choice.

A web page cannot stop a phone camera, a capture card or capture tools that do not take focus, and
Android and iPhone do not let a website block screenshots. The watermark makes a leaked picture
traceable to the person and time.

## "Page expired" on live weight and gate screens

Screens that refresh every second got 419 as soon as the session's security token changed (signed in
again or switched account in another tab, or the session ended), and Livewire showed "This page has
expired". `public/js/page-expiry.js` reloads the page quietly when an automatic refresh gets 419;
a 419 on something the person did keeps Livewire's own question so typed input is not lost.
