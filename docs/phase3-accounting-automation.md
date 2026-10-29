# Phase 3: accounting automation

Migration `2026_11_25_000100_bank_email_and_roznamcha`.

## Bank notification e-mails

### Product owner (Platform)
**Email integration** shows:
* which providers this server can sign in with;
* the sync policy;
* the health of every connected mailbox, with recent errors;
* a switch per customer to allow bank e-mail import.

**Bank e-mail templates:** how each bank's alert is read, as regular expressions for:
* sender and subject;
* amount;
* money in or out;
* reference;
* our account, and the other side's name and account or IBAN;
* date.

**Test with an e-mail** reads a pasted alert, so a template is checked before customers rely on it.

**The OAuth apps are the product owner's.** Their ids and secrets go only in `.env`:

| Setting | Meaning |
|---|---|
| `BANK_EMAIL_GOOGLE_CLIENT_ID`, `BANK_EMAIL_GOOGLE_CLIENT_SECRET` | Gmail (scope `gmail.readonly`) |
| `BANK_EMAIL_MICROSOFT_CLIENT_ID`, `BANK_EMAIL_MICROSOFT_CLIENT_SECRET`, `BANK_EMAIL_MICROSOFT_TENANT` | Outlook / Microsoft 365 (`Mail.Read`) |
| `BANK_EMAIL_IMAP` (default true) | Other mailboxes with an app password |
| `BANK_EMAIL_REDIRECT_URI` | Only if the server is reached under another name. Default `/admin/bank-email/callback` |
| `BANK_EMAIL_MIN_SYNC_MINUTES` (default 15) | Shortest interval a mill may choose |

Register the redirect URI with Google and Microsoft. **Google requires app verification** for `gmail.readonly` before mailboxes outside its test-user list can connect. IMAP works without it.

### Mill: Owner or Super Admin (Accounts → Bank e-mail)
* **Connect a mailbox:** provider, address, associated bank account and sync frequency.
  * Gmail and Outlook open their own sign-in page (OAuth with PKCE, read-only).
  * IMAP takes the server, username and an **app password**.
  * Tokens and passwords are stored encrypted and never shown again.
* **Each mailbox shows:** status, last successful sync, last error, **Sync now**, **Import history**, and **Disconnect** (which deletes the saved sign-in).
* **Scheduled reading:** the scheduled job `bank-email:sync` (every 5 minutes) reads each mailbox when its own interval has passed, away from people's screens. A failed reading is marked "Error" and tried again.

### What is read (Accounts → Bank e-mail transactions)
* **Each e-mail is kept once** (unique per mailbox).
* **E-mails from senders with no template are ignored.**
* **The fields read:** Transaction ID (`AMR/BNK/…`), bank reference, date and time, bank, account, debit or credit, amount, sender or receiver, their account, party, party ID, description (the e-mail text), source e-mail reference, import status and reconciliation status.
* **Duplicates:** the same account, date, side, amount and reference already read is marked **Duplicate** and set aside. An e-mail that cannot be read is **Could not read**, with the reason.
* **Party matching uses verified facts only:**
  * the other side's full IBAN or account is registered to exactly one party (`PartyBankAccount`, via its blind index) → **Matched**;
  * the party's code appears in the reference → **Matched**;
  * only the last four digits or the name fit → **Check the party**: suggested, never assigned without a person;
  * otherwise → **No party**.
* **Review:**
  * **Confirm**: optionally choose or correct the party;
  * **Ignore**: with a reason;
  * **To bank statement**: needs `ledger.post`. The transaction becomes a bank statement line and is reconciled with the books by the existing matching (`BankReconciliation::autoMatch`).
* **An e-mail alone never posts anything to the books.**

## Digital Roznamcha (Accounts → Roznamcha)
* **Add a page:** date, cash account, and input from:
  * **mobile camera** (the upload opens the camera on a phone);
  * **scanner** (save the scan as an image or PDF);
  * **PDF**, **image**, or **typed by hand**.
* **The original is kept** in the content-addressed store; its SHA-256 proves it unchanged. **Open the original page** shows it.
* **OCR suggests the entries:**
  * **Tesseract** on the mill's server (the default, `RICEMILL_OCR_ENGINE=tesseract`). This machine has English; for Urdu install the `urd` data and set `RICEMILL_OCR_LANG=eng+urd`. `TESSERACT_PATH` points to the program when it is not on the PATH, for example `C:\Program Files\Tesseract-OCR\tesseract.exe` here.
  * **The AI reader** (`RICEMILL_OCR_ENGINE=claude`, with `ANTHROPIC_API_KEY`): better for handwriting and Urdu, and reads PDFs. The page image is sent to Anthropic, so it is off unless switched on.
* **Correct entries:** each entry has a party or account, cash received (jama) or paid (banam), narration and reference.
* **Mark checked:** a **second person** checks the page against the scan. The person who entered it may only when the mill lets main users approve their own work.
* **Post to the books:** each entry becomes a cash **receipt** or **payment** voucher, once. A posted page cannot change.
* **Every page records** who entered it and who checked it.
* **Old pages:** search and filter by date. Totals received and paid show per page, and **Print** gives the daily report.

## Not covered (limits)
* **Scanners:** a browser cannot drive a scanner directly (TWAIN or WIA). Scans are saved as image or PDF files and uploaded.
* **Duplicate statement lines:** a bank statement CSV imported later is matched by its own fingerprint. A line already sent from an e-mail is not detected as the same line, so do not import both for the same days, or ignore one of them.
* **Untested against real providers:** the Gmail, Outlook and IMAP readers are tested with fakes. Connecting real mailboxes needs the OAuth apps above (or an IMAP app password).

## Tests
`tests/Feature/Fixes/Phase3AccountingAutomationTest.php` (10 tests).
