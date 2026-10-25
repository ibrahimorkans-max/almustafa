# The system as a SaaS product

## Customers, mills and the platform
| Level | Who | Sees |
|---|---|---|
| **Platform** | The provider's own accounts (`is_platform_admin`) | Every customer: platform console, whole-system lockdown, maintenance, settings for every customer |
| **Customer** | A company using the system, with its Owners | All mills of that customer, never another customer's |
| **Mill** (entity) | Super Admin, staff, parties | That mill only, as before |

* **Existing data:** it became the first customer, **C0001** (named after the brand).
* **Every mill belongs to one customer.** A new mill goes to the customer it was given, else to the signed-in Owner's customer. On a first install with no customer, it gets a new customer of its own.

### How customers are kept apart
* **In the database (PostgreSQL):** every mill-owned table already had row-level security keyed on the mill. The policy now also allows an Owner working across "all firms" to see the mills of **its own customer** (`app.customer_id`), and never any other.
  * Only the system and the platform admin switch isolation off.
  * The `entities` and `persons` tables follow the same rule.
* **In the application (both databases, and the only guard on MariaDB):** the Eloquent scope and the write guard follow the same rule. An Owner can write records only in its own customer's mills.
* **Sign-in:** a working mill that belongs to another customer is never honoured, in the browser or the API; the Owner is sent back to its home mill.

### What belongs to the platform admin only
These would otherwise affect every customer:

| Power | Who now |
|---|---|
| Changing the **Owner role** (shared by all customers) | Platform admin only |
| **Whole-system lockdown** | Platform admin only. It also blocks customers' Owners. A customer's Owner locks and unlocks **its own mills only**. |
| **Maintenance windows** | Platform admin only |
| **Sign-in settings** for every customer, the **all-mills screen-protection** value, any **setting for every customer** | Platform admin only |

Customers' own settings:
* **Office Desk retention:** each customer sets its own, applied to all its mills. The monthly archive runs mill by mill with each mill's own period.
* **Security notices:** stay inside the customer.
  * **About one mill:** that mill's Super Admins and its customer's Owners.
  * **About the whole system:** the platform admins.
* **Security Centre:** a customer's Owner sees only its own mills' lockdowns.

### The product owner's own account
The provider has its **own company**, apart from every customer. Create it once on the server:
```bash
php artisan saas:create-platform-owner saas.owner --name="Your name" --revoke-others
```
* **The command creates:**
  * a company named after the product, with no plan, so it is never invoiced and never paused;
  * an office "mill" (code HQ) to hold the login;
  * the Owner login `saas.owner`, which is the platform admin.
* **The temporary password** is written to `storage/app/private/saas-owner-login.txt`, never shown on screen. At first sign-in you choose your own password and set up two-step sign-in. Then delete the file.
* `--revoke-others` stops any other account (for example a customer's Owner) being a platform admin.

**What the product owner sees:**
* **Its menu:** Platform (Overview, Customers, Invoices, Plans), plus Administration and Security. That covers its own logins, roles, approvals, settings, the Security centre, the audit log, sessions, devices and sign-in rules.
* **No mill screens:** gate, procurement, stock, accounts, sales, HR, masters, reports and the assistant are all hidden.
* **Its start page** is Platform → Overview. "My subscription" is hidden; it has no subscription.
* **What it keeps:** the platform rights (`LoginAccount::PLATFORM_RIGHTS`): users, roles, devices, sessions, audit, settings and approvals.

**Nobody else sees the Platform menu.** Every Platform screen opens only for a platform admin, and that includes a customer's Owner.

### Making a platform admin
Only from the server's command line, never from a screen, so no customer can grant it:
```bash
php artisan saas:platform-admin <owner-username>
php artisan saas:platform-admin <owner-username> --revoke
```
The account must be an Owner. The change is recorded in the audit log.

## Plans and limits (phase 2)
A customer's plan decides which **modules** it may use, and three **limits**:
* **Mills:** checked when a mill is added.
* **Staff users:** checked when a login is made. Party logins and the platform's own accounts don't count.
* **Vehicles a month:** checked when a vehicle arrives at the gate; counted by calendar month in Pakistan time.

A limit left empty is unlimited. A customer with no plan keeps everything.

### Modules
| Module | What goes with it |
|---|---|
| Gate, weighbridge & lab | Vehicles at the gate, weights, lab, negotiation and settlement |
| Procurement & deals | Requirements, quotes, saudas |
| Stock & production | Stock, requisitions, milling batches, stock counts |
| Accounts & payments | Bills, payments, vouchers, ledgers, bank, period locks |
| Sales, dispatch & GST | Sales deals, dispatch orders, invoices, tax reports |
| Claims & pricing | Claims, price lists, markups |
| HR, payroll & daily wages | Employees, attendance, leave, payroll, documents, daily wages |
| Party accounts & messages | Party logins and messages |

**Always included:** masters, administration, security, dashboard and reports, the Office Desk.

How a module is switched off:
* Each right (permission) belongs to at most one module. A right of a module the plan doesn't include is simply **not held**, so its screens, buttons, reports and API calls go away together. This is enforced in one place, `LoginAccount::permissionCodes()`.
* Roles aren't changed. Moving to a bigger plan brings the rights straight back.

### Starting plans (change them in Platform → Plans)
| Plan | Price / month | Mills | Users | Vehicles / month | Modules |
|---|---|---|---|---|---|
| Basic | Rs 15,000 | 1 | 5 | 300 | Gate, procurement, stock, accounts |
| Standard | Rs 35,000 | 3 | 20 | 1,500 | + sales, claims, party messages |
| Pro | Rs 60,000 | unlimited | unlimited | unlimited | everything |

The first customer (C0001) starts on **Pro**.

### Screens
* **Platform → Plans** (platform admin only): name, price, limits, modules, offered or not, and how many customers are on each plan. A plan is never deleted; it's made "not offered".
* **Administration → My subscription** (a customer's Owner): the plan and price, usage against each limit with a bar, and the modules included.

## New customers and free trials (phase 3)
**Only you (the platform admin) open customers.** There is no public sign-up page, and a customer can't create a new company or mill itself.

### Platform → Customers → New customer
One step opens the company, its plan and trial, its **first mill** (with the mill's roles, chart of accounts, HR, tax and lab defaults) and its **first Owner login**.
* **Company:** company, contact, plan, and whether it starts as a **free trial** (number of days) or **active (paid)**.
* **First mill:** name, optional short code, city. The mill code is made from the name's initials ("Bismillah Rice Mill" → BRM) when none is given.
* **Owner:** the Owner's name, username, and a **temporary password**, which must be changed at first sign-in.

### More mills for a customer: its page → Add a mill
* **The form:** name, optional code, city, province, NTN and STRN (checked, and stored encrypted).
* **The plan's mills limit** still applies.
* **The customer's Owners** see the new mill at once.
* **Administration → Entities** no longer has a "create" button for anyone.

### When access stops
| Customer state | Its people |
|---|---|
| Free trial, still running | Use the system |
| **Trial ended** | Sign in to the payment page only (see below) |
| **Blocked** | Sign in, but see **none of their data**: every data screen sends them back to the start page, which says "Your account is blocked. All of your company's data is hidden until it is unblocked." No rights, no notification bell, no app or API. Owners can still open My subscription to pay. |
| **Closed** (cancelled) | Can't sign in; nothing is deleted |
| Active, or payment due | Use the system (read-only when an invoice is overdue past the grace days) |

* The check runs at sign-in, on every page and on every app call.
* The platform admin is never shut out.
* Put your phone number or email in `SAAS_CONTACT` so it appears in these messages.

### Platform → Customers (platform admin only)
* **The list:** each customer with its plan, its state (for a trial, "Trial · N days left" or "Trial ended"), and its mills, users and vehicles this month.
* **Block / Unblock**, shown as a button on every row.
  * **Block** needs a reason (kept in the audit log). From the next click, the company sees none of its data (see above). It isn't sent to the payment page.
  * **Unblock** lets everyone in again.
  * You can't block the platform's own company.
* **Edit:** company details, plan, state and trial end.
* **In the row menu:** Extend trial, Activate (paid), and Give or End lifetime access.
* **A customer is never deleted.**
* Platform admins get a bell notice for each new customer.

### Trial reminders
Every day at 09:00 (`saas:trial-reminders`), a customer's Owners get a bell notice 3 days before, and 1 day before, their trial ends.

### The first Owner's first device
* **Before:** an Owner's new device waits for **another Owner** to approve it.
* **The problem:** a new customer has no other Owner.
* **Now:** for a customer opened by sign-up or by the platform, its first Owner's **first** device is trusted, once. After that the usual rule applies.
* **The first installation's customer (C0001)** is unchanged.

## Billing (phase 4)
Monthly invoices, paid by **bank transfer**:
1. The invoice is issued.
2. The customer sends the transfer and presses **"I've paid"**, giving the reference and, if it has one, the receipt.
3. You check your bank and press **Mark paid**.

### Invoices
* **Monthly, on the 1st at 06:00** (`saas:invoices`): this month's invoice for every customer that is **active** or **payment due** and on a plan with a price.
  * **Description:** for example "Standard plan, November 2026", at the plan's price.
  * **Not billed:** trials, paused customers and closed customers.
  * **Running it again** issues nothing twice.
* **Numbers:** one gap-free series for the whole platform, for example `SUB-2026-0001`. Change the prefix with `SAAS_INVOICE_PREFIX`.
* **Due date:** `SAAS_INVOICE_DUE_DAYS` (10) days after it is issued, at midnight Pakistan time.
* **When a trial becomes paid:** Platform → Customers → **Activate (paid)** can issue this month's invoice at once (switched on by default for a trial).
* **Other charges:** Platform → Invoices → **New invoice**, for example setup and training. Choose the customer, the description, the amount and the number of days until it is due.
* **The PDF** shows:
  * your company name, address and NTN;
  * the customer;
  * the charge;
  * a DUE, OVERDUE or PAID stamp;
  * **the bank details for the transfer**, which you set in `.env`:

```
SAAS_SELLER_NAME="Your company (Pvt) Ltd"
SAAS_SELLER_ADDRESS="..."
SAAS_SELLER_NTN=...
SAAS_BANK_TITLE="Your company (Pvt) Ltd"
SAAS_BANK_NAME="..."
SAAS_BANK_ACCOUNT=...
SAAS_BANK_IBAN=PK..
```

### The customer: Administration → My subscription
* **Its invoices:** number, what for, amount, due date and state, with the **PDF** of each.
* **The bank details.**
* **"I've paid"** on an open invoice. You get a bell notice. The form asks for:
  * **Paid with:** which of your payment methods. It's required when any are offered, and prefilled when there's only one.
  * **Transaction ID**, typed **twice**. The two must match, so a typo is caught before it's saved.
    * It's stored cleaned: spaces removed, capital letters ("ft2611 2233" → `FT26112233`).
    * It must be 4–60 letters, numbers, `/ . _ -`.
    * **A transaction ID is accepted for one invoice only.** Given again, even by another company, it's refused, naming the invoice that already has it.
  * **Amount sent:** prefilled with the invoice amount.
  * **The day paid,** a receipt photo or PDF (optional), and a note.
* **Correcting a mistake:** once reported, the button reads **"Correct the transaction"**.
* **Each invoice is a card** (on My subscription and the payment page). It shows the state: Due, Overdue, Being checked, or Paid.
  * **While being checked:** the transaction ID, how it was paid, the amount and the day.
  * **Once paid:** the payment received and its transaction ID.
* **On your side:**
  * **Platform → Invoices** shows the reported transaction ID (searchable, with a copy button), with the method, the amount ("less than due" if short) and the day. The overview and the invoice PDF show it too.
  * **Mark paid** is prefilled with the reported transaction ID and amount. The ID is cleaned the same way, and one already used to pay another invoice is refused.
* **A notice at the top of every page:**
  * for its Owners: an invoice due within 3 days, or overdue;
  * for everyone in the company: read-only.

### Platform → Invoices (platform admin only)
* **The list:** every invoice with its customer, amount, due date and state ("Overdue 5 d").
  * **What the customer reported:** its reference and the day it says it paid.
  * **What you received.**
* **Filters:** "Payment reported, not yet checked", Overdue, state and customer.
* **The menu badge** counts payments reported but not yet checked.
* **Row actions:**
  * **PDF**;
  * **Customer's receipt**;
  * **Mark paid:** the amount received, the day and the bank reference. These are filled in from the customer's report; check them against your bank first. An amount less than the invoice is refused.
  * **Cancel invoice:** needs a reason.
* **Issue this month's invoices:** does the monthly run now.

### When a customer doesn't pay
Every day at 09:15 (`saas:billing-check`):

| When | What happens |
|---|---|
| 3 days before the due date | Bell notice to its Owners |
| On the due date | Bell notice |
| The day after | "Overdue" bell notice, saying when the system becomes read-only |
| More than `SAAS_GRACE_DAYS` (7) days overdue | **Read-only** ("Payment due"). Its people can open and print everything but can't record or change anything. Its Owners and you are told. |
| `SAAS_SUSPEND_AFTER_DAYS` days overdue (0 = never, the default) | **Paused:** nobody can sign in |

* **What read-only means:** every right that records or changes something is held back, so those buttons, screens and app calls disappear together. Only rights that show things stay: names ending in `.view`, `.view_own` and `.view_all`, plus tax reports. Roles aren't changed.
* **Once you mark the overdue invoice paid** (or cancel it), the customer is **fully open again at once**, and its Owners are told.
* **The platform admin** is never read-only.

## Platform console (phase 5)
### Platform → Overview
* **Money:** monthly recurring income (active and payment-due customers at their plan price), invoiced and collected this month, outstanding, and overdue.
* **Customers by state:** free trial, trial ended, active, payment due, paused and closed, plus how many are new this month. Each count opens that filtered list.
* **Last six months:** invoiced and collected, month by month.
* **Needs attention:**
  * payments reported but not yet checked;
  * overdue invoices, with days late and whether the customer is read-only;
  * trials ending within 7 days, with the contact's name and phone;
  * customers using **80 % or more** of a plan limit (a reason to offer a bigger plan);
  * the newest customers, and how they came.

### One customer's page (Platform → Customers → click a row)
* **Company:** contact, phone, email, address, how it came, since when, and notes.
* **Subscription:** plan, price and state, plus billed in all, paid, and open.
* **Usage** against each limit, with bars.
* **Mills:** code, name, city and active logins.
* **Owners:** username, name, state, whether two-step sign-in is set up, and last sign-in.
* **Invoices,** with their PDFs.
* **History:** what happened to the customer and its invoices (opened, plan or state changed, paused, paid, cancelled).
* **Buttons:** Edit, and New invoice.

Code: `app/Domain/Saas/PlatformStats.php`, `App\Filament\Pages\Platform\PlatformOverview`, `CustomerResource\Pages\ViewCustomer`, and the views under `resources/views/filament/pages/platform/`. Tests: `tests/Feature/Saas/PlatformConsoleTest.php`.

## Lifetime access
**Platform → Customers → (row menu) Give lifetime access**, also on each customer's page.
* **Asks for:** a note (why, kept in the history) and whether to cancel its open invoices (on by default).
* **The customer becomes:**
  * active for good: no trial end, no more monthly invoices;
  * never read-only or paused for a bill.
* **Where it shows:** a "Lifetime" badge in the list, on its page and on its My subscription. Its Owners see "Lifetime access: no payments due, ever".
* **What you can still do:** pause it yourself, or **End lifetime access**. It stays active and is billed again from the next monthly run.
* **The overview** counts lifetime customers separately and leaves them out of monthly recurring income.

## Ways to pay, with QR codes
**Platform → Payment methods**: as many as you like.
* **Each one has:**
  * a name and type (bank account, mobile wallet, Raast, other);
  * account title, account or mobile number, IBAN;
  * instructions;
  * a **QR code**: upload the picture your bank or wallet gave (PNG or JPG, kept privately), or type a text to make one (a Raast ID or a payment link);
  * an order, and whether customers see it.
* **Customers see the offered ones** as cards with the QR on My subscription and on the payment page. They're also on every invoice PDF (two to a row, with the QR).
* **With none set up,** the `.env` bank details are shown as before.

### Free trial for any length
* **New customer:** "Trial days", prefilled with **14** (any number from 1 to 365).
* **Set trial period** (Customers → row menu, or the customer's page): gives any company, and with it all its mills, a free trial of any length from today, prefilled with **14** days.
  * It works on an active, blocked or trial-ended customer as well.
  * Not for lifetime customers, closed ones, or the platform's own company.
  * Recorded as `saas.customer.trial_set`.

## Trial ended: the payment page only
* **A customer whose free trial has ended, or whose access is paused:**
  * **can sign in,** but every screen sends it to **Payment needed**. That page has no menu: just why, what is due (invoice PDFs), the ways to pay with their QR codes, **I've paid** (Owners only; staff are told to ask the owner), and Sign out.
  * **has no rights** while it lasts, so no button works anywhere. A Livewire call from any other screen (for example an old browser tab) is refused. The mobile app and API are closed too.
* **When a trial ends,** its first invoice is issued at once (on the page, and by the daily billing run), so it can be paid straight away.
* **When you mark it paid,** a trial-ended or paused customer is **active again at once**.
* **A closed (cancelled) customer** still can't sign in at all.

Code: `CustomerAccess` (`signInBlocked()`, `paymentDue()`), `PaymentGate`, `PaymentRequiredPage`, `Billing::grantLifetime()` / `revokeLifetime()`, `SaasPaymentMethod`, `PaymentMethodResource`, migration `2026_11_21_000100_saas_lifetime_and_payment_methods`. Tests: `tests/Feature/Saas/PaymentFlowTest.php`.

### A stopped company shows nothing, anywhere
This applies while a customer is paused, its trial has ended, or it is closed:
* **Its people:** only its **Owners** see the payment page's invoices, amounts and ways to pay. Staff and party logins see only "Your access is paused: the system opens again once your company's owner has paid", and Sign out.
* **The notification bell is closed,** because its notices hold the company's data. So are the invoice PDFs, for anyone but its Owners.
* **Shared document links** (payslips and letters sent by WhatsApp, SMS or email) stop opening.
* **The public verify page** confirms a document was issued by the system, but shows none of its figures: "The issuer's account is paused, so its details are not shown."
* **No messages go to its parties** (WhatsApp, SMS, push).
* All of this comes back as soon as it is paid. The check is `CustomerAccess::millBlocked()`.

## The product owner's overview
Platform → Overview has:
* **Six cards with trend lines:** monthly recurring income, collected this month, overdue, active customers, on free trial, and payments to check. Each card opens its list.
* **Five charts,** drawn with the page (they used to load afterwards and could stay empty):
  * income: invoiced against collected, six months;
  * customers by state;
  * new customers a month: signed up, or added by you;
  * customers per plan, with lifetime shown apart;
  * invoices of the last 12 months: paid, not yet due, overdue, cancelled.
* **Quick buttons:** New customer, Invoices, Payment methods.
* **Cards:** payments to check, overdue invoices, trials ending, customers ready for a bigger plan, and the newest customers.
* **Platform is first in the product owner's sidebar:** Overview, Customers, Invoices, Payment methods, Plans. Administration and Security follow.

## Each customer's own look on paper
Every mill sets its own name, Urdu name, logo, colours, invoice terms, slip note, footer line and bank account under **Administration → Print & branding**. See [print-branding.md](print-branding.md).

## Code
* Migration `2026_11_16_000100_saas_customers`: customers, `entities.customer_id`, `login_accounts.is_platform_admin`, database functions `app_customer_id()` and `app_in_customer()`, and every table's policy.
* `TenantContext::enterCustomer()`, `EntityScope`, `Entity` scope, `BelongsToEntity::guardEntity()`.
* `AuthContextBinder`, `ApiTokenGuard`.
* `Lockdown`, `MaintenanceService`, `SecurityNotifier`, `SettingsService`, `ChangeRolePermissions`, `RolePolicy`.
* `SecurityCentre`, `ScreenProtection`, `SecuritySettings`, `ArchiveSearch`, `archive:run`.
* `saas:platform-admin`.
* Plans: `app/Domain/Saas/Modules.php`, `app/Domain/Saas/PlanLimits.php`, migration `2026_11_17_000100_saas_plans`, `LoginAccount::permissionCodes()`, the limits in `EntityProvisioner`, `LoginAccount` (creating) and `GateService::arrive()`, `PlanResource` (Platform → Plans), `SubscriptionPage` (My subscription).
* New customers and trials:
  * `app/Domain/Saas/CustomerOnboarding.php` (opens a customer);
  * `app/Domain/Saas/CustomerAccess.php` (trial over, blocked or closed), checked in `LoginService`, `EnsureWebAuthSession` and `ApiTokenGuard`;
  * `CustomerResource` (Platform → Customers, with Block / Unblock), `ViewCustomer` (Add a mill); `EntityPolicy::create()` refuses everyone else;
  * `saas:trial-reminders`;
  * `DeviceService` (a new customer's first Owner device);
  * migration `2026_11_18_000100_saas_customer_source`;
  * the `ricemill.saas.*` config.
* Billing:
  * `app/Domain/Saas/Billing.php`, `app/Models/SaasInvoice.php`, migration `2026_11_19_000100_saas_billing`;
  * `SaasInvoiceResource` (Platform → Invoices), `SubscriptionPage` (invoices and "I've paid"), `SaasInvoiceController` (PDF and receipt), `resources/views/pdf/saas-invoice.blade.php`;
  * read-only: `CustomerAccess::readOnly()` and `viewOnly()`, used in `LoginAccount::permissionCodes()`;
  * the banner `filament.hooks.billing-banner`;
  * `saas:invoices` and `saas:billing-check`.
* Bell notices for SaaS are sent as the system (`Billing::notify`). A notice is a queued job that loads its recipients again, and from inside one customer another customer's accounts would not be found.
* Tests: `tests/Feature/Saas/CustomerIsolationTest.php`, `tests/Feature/Saas/PlansTest.php`, `tests/Feature/Saas/OnboardingTest.php`, `tests/Feature/Saas/BillingTest.php`.
