# Archive: one screen to view and restore, invisible everywhere else

**Where:** Administration → **Office Desk** (`/admin/office-desk`). Only the **Owner** and each mill's **Super Admin** can open it.

## What gets archived
Closed working records are archived automatically, `archive:run` on the 1st of each month, once they are older than
the retention period (`RICEMILL_ARCHIVE_RETENTION_MONTHS`). The record types are:
* saudas;
* gate entries;
* milling batches;
* requisitions;
* claims.

Nothing is ever deleted. Archiving only marks the record. The books, stock ledger, bills and audit log are never
archived, so **accounts, stock and tax totals do not change**.

## The Archive screen
1. Choose the period. **Closed from … to …** uses the date each record was closed.
2. Optionally pick a type, and type a number, vehicle or party name.
3. **Show records** lists them, newest first, up to 500.
4. **View** opens the whole record, read-only. A gate entry shows its complete vehicle details: vehicle, driver, trip,
   weighments, lab test, weight and price working, and timeline.
5. **Restore** brings one record back. **Restore all in this period** brings back every listed record. Both
   need a written reason, which is kept in the audit log.

There is **no print, download, Excel or CSV** on this screen. Printing the page from the browser gives a blank sheet.

## Everywhere else
* No list has an "Archived" filter any more. Archived rows are never shown on these screens:
  * gate entries;
  * saudas;
  * milling batches;
  * requisitions;
  * claims;
  * employees.
* Opening an archived record's address gives "not found". Its print links (vehicle slip, record print) give "not
  found" too.
* Reports leave archived records out:
  * gate register and vehicle register;
  * production yield;
  * the business report's vehicle and claim lists;
  * the all-reports book.
* The assistant does not find them either.
* A restored record is back everywhere at once.

## A separate archive account
Use the **Office Coordinator** role to give someone the archive and nothing else.

1. As Owner, choose the mill.
2. Go to **Administration → Users → New**.
3. Pick any username, set role **Office Coordinator**, and give a temporary password.

On first sign-in, the person sets their own password and two-factor code. After that, the account:
* goes straight to the Office Desk screen, and the menu shows only **Office Desk**;
* is refused every other screen, report and print link, as well as the assistant and the Sessions list;
* can view archived records by period and restore them, with a reason that is kept in the audit log;
* is listed under Users and Access review like every other account.

## Rights
`office.view` (view) and `office.restore` belong to the Owner, the Super Admin and the Office Coordinator only. The release removed
the view right from the Accountant role and wrote each removal to the audit log. To give archive access to another
role, use the normal role approval.

## Code
* `app/Filament/Pages/ArchiveSearch.php`, `resources/views/filament/pages/archive-search.blade.php` and
  `archive-record.blade.php`.
* `app/Domain/Archive/ArchiveService.php` (`browse`, `show`, `restore`, `restorePeriod`).
* `app/Filament/Support/HidesArchived.php` is the fixed rule on working screens. It replaces the old `ArchivedFilter`.
* Migration `2026_11_02_000100_limit_archive_to_owner_and_super_admin`.
* `tests/Feature/Archive/ArchiveScreenTest.php`.

## When closed records move here
The Owner or the Office Coordinator chooses it on the Office Desk screen, under **When closed records move here**. The choices run from 3 to 60
months after closing, and one setting covers all mills. Until the Owner chooses, `RICEMILL_ARCHIVE_RETENTION_MONTHS`
applies (default 24). The monthly run on the 1st uses the new value. Each change is recorded in the audit log, with
the old and new number of months. Only closed records ever move, and accounts, stock and tax totals are not
affected.

## Panic button (device lost or stolen)
Every account except the Owner has a red **Panic: device lost or stolen** button at the top of the Office Desk.
After a confirmation, with an optional note on what happened, it does all of this at once:
* it signs the person out here and on every other device;
* phones, the app and gate PCs signed in to this account have their stored data wiped when they next connect, and
  until then every request from them is refused;
* browsers signed in to this account are blocked;
* the account is locked until an administrator unlocks it (**Users → Unlock**), so a stolen password cannot be used
  either;
* the Owner and the mill's Super Admins get an alert;
* the press is recorded in the audit log with the note.

No business record is changed, hidden or deleted. To get back in, an administrator unlocks the account; the person
then signs in again, and any blocked device is approved again. The Owner doesn't have the button, because there is
nobody above the Owner to unlock the account.

Code: `app/Domain/Security/PanicButton.php`.
