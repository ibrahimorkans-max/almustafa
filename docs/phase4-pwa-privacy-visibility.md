# Phase 4: devices, location, privacy and data visibility

Migration `2026_11_26_000100_pwa_location_visibility`.

## Device registration (Security → My devices)
* **Registration happens at sign-in.** Every phone, computer and installed app a person signs in from is registered with their existing account. The existing rule still applies: a new device may need the Super Admin's approval (Security → Sign-in rules → New phones and computers).
* **My devices** lists each person's own devices:
  * a **registration ID** (`DEV-1A2B-3C4D`) to read out to the Super Admin;
  * type: browser, installed app, Android or iPhone;
  * whether it is approved;
  * when it was registered;
  * **last activity** and its address.
* **Remove** signs a device out and stops it signing in again. It is not offered for the device in use.
* **The Super Admin's view:** all devices are under Security → Devices, where they can also be revoked, marked lost or wiped (as before).
* **Device limit** (Security → Sign-in rules → Devices per person: no limit, or 1 to 10):
  * a new device is refused once the person already uses that many, with a message telling them to remove one;
  * gate computers are not counted;
  * the product owner is never limited.
* **The installed app (PWA):**
  * "Add to Home screen" installs MillNexa;
  * a sign-in from the installed app registers the device as **Installed app**;
  * the app keeps no copy of pages or data on the phone (`public/sw.js` is network-only), so a lost or signed-out phone shows nothing.

## Location
* **Asked only when needed:**
  * sign-in inside the mill (as before);
  * attendance (as before);
  * entries, when the mill switches on **Record location with entries** (Security → Sign-in rules).
* **Kept with entries:** gate entries, paddy stock entries, expenses and roznamcha pages store where the device was (latitude, longitude, accuracy, time).
  * The place shows on the record as **Entered at**, linked to the map, and as a column in the expense list.
  * If the person refuses location, the entry is saved without it.
* **My devices** shows whether this browser may share its location (allowed, not asked yet, blocked), with **Allow location** to ask.

## Screenshot privacy and exports
* **Screen protection (as before):** a watermark with name, IP and time; the page blanks when capture tools start; browser printing is blocked. It is set under Security → Screen protection.
* **Report PDFs** carry a faint diagonal watermark (who printed it, and when) while screen protection is on.
* **Masking (as before):** CNIC, bank accounts and similar identifiers are masked unless the person has the right to see them, and each reveal is recorded.
* **Downloads need the new right "Download reports and lists" (`report.export`):**
  * this covers report Excel, CSV and PDF downloads, and printing a whole list;
  * without it, reports are seen on screen only, and attempts are refused and recorded;
  * printing a single invoice, slip or voucher is not affected;
  * every role that had report access was given the right, so nothing changes until the Super Admin takes it away.
* **Limit:** a web page cannot fully prevent screenshots (the system, other programs and phone cameras are outside its reach). Stronger blocking needs a native app (Android `FLAG_SECURE`).

## SaaS: customer data visibility (Platform → Data visibility)
* **Who sets it:** the product owner.
* **Fields:**
  * customer, or plan (a plan rule is the default for its customers);
  * module (all, procurement, gate, stock and production, sales, accounts, claims);
  * visibility duration in days or months;
  * start date and expiry date;
  * grace days;
  * status;
  * a manual override with its reason.
* **Which rule decides:** the customer's rule for the module, then the customer's "all" rule, then the plan's rule for the module, then the plan's "all" rule.
* **What a rule does:**
  * records older than the window are hidden;
  * after expiry plus grace, nothing of the module is shown;
  * an override shows everything until its date;
  * an inactive rule hides nothing.
  * **Extend** and **Restore full access** are on each rule.
* **Where it applies:** screens and lists, record pages (hidden records answer "not found"), search, the Code Directory, list prints, report periods (they start at the first visible day; opening balances stay complete) and the API.
* **A notice on every page** tells the customer which records are not shown.
* **What is not limited:**
  * nothing is deleted, and balances, stock and the books' own arithmetic stay complete;
  * scheduled jobs are not limited;
  * the product owner sees everything.
* **Every change** is in the audit log.

## Tests
`tests/Feature/Fixes/Phase4PwaPrivacyVisibilityTest.php` (7 tests).
