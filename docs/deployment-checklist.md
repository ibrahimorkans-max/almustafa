# Deployment checklist — Al Mustafa Rice Mills

The server set-up itself is in [infra/vps/README.md](../infra/vps/README.md). This list covers what is specific to the mill, in order.

## Before the first deploy
1. **Fresh database only.** Earlier phases were changed in place while there was only demo data, so production must start from an empty `ricemill` database. Never copy the dev database.
2. In `/etc/ricemill/ricemill.env` set:
   * `APP_URL=https://<domain>`
   * SMTP mail settings
   * `RICEMILL_AUDIT_ANCHOR_EMAIL` (the owner)
   * the off-site bucket (`RICEMILL_OFFSITE_*`)
   * the Super Admin IP allow-list
3. Copy `RICEMILL_BACKUP_KEY` off the server (password manager or a sealed envelope with the owner).
4. Tax settings: leave them at their defaults until the tax adviser confirms them.
   * `RICEMILL_FURTHER_TAX_RATE_BP` (default 400 = 4 %).
   * `RICEMILL_WHT_ENABLED`: `false` until the adviser confirms the mill is a withholding agent. The rates are `RICEMILL_WHT_RATE_FILER_BP` and `RICEMILL_WHT_RATE_NON_FILER_BP`.

## First deploy
1. `deploy.sh main`. It runs, in this order:
   * `ricemill:preflight --before-migrate`
   * a backup, when there is a previous release
   * `migrate`
   * the full `ricemill:preflight`
   * the caches
   * the switch to the new release and a health check, with rollback if the check fails

   Any FAIL line stops the deploy before users see the new release.
2. `php artisan db:seed --class=AccessCatalogSeeder --force`, then `php artisan ricemill:create-super-admin`.
3. Sign in as Super Admin. `RICEMILL_BOOTSTRAP_TRUST_FIRST_DEVICE=true` is allowed for this first sign-in only; set it back to `false` afterwards.
4. Under **Entities → New**, create the mill:
   * code `AMR`, name and legal name **Al Mustafa Rice Mills**;
   * address *Adda Permat, 1 km Shujabad Road*, city *Lodhran*, province *Punjab*, phone *0300-6834588*;
   * **NTN** and, if registered for sales tax, **STRN**.

   Creating the mill also installs:
   * roles and the chart of accounts;
   * HR pay heads, leave types, the general shift and Pakistan's fixed public holidays;
   * the two sales-tax rates, *Exempt (Sixth Schedule)* and *Standard 18%*.
5. Create the owner's login account. The owner sets up 2FA at first sign-in.
6. Run `php artisan ricemill:preflight` again. It must show no FAIL lines. The warning *Mill NTN entered* disappears once the NTN is saved.

## Master data the mill enters (once)
* **Products**: paddy varieties, rice grades, by-products and packing, each with its sale unit (maund / bag / ton), PCT code and sales-tax rate.
* **Godowns, weighbridge**: the weighbridge needs its Weights & Measures certificate.
* **Deduction rules.**
* **Mill bank account(s)**: as IBAN.
* **Parties**:
  * NTN / CNIC and STRN;
  * the FBR Active Taxpayer List status, which sets the withholding rate;
  * bank accounts as IBAN, approved by a second person.
* **Employees and the attendance site**: the site's location and radius.
* **Holidays**: Eid ul Fitr, Eid ul Adha and Ashura, once announced each year.

## After every deploy
* Open `/health`, which must report database and cache OK.
* Print one weighment slip and one invoice and check them at `/verify` by scanning the QR code.
* On the gate phones, reload the PWA once, so the new app version and icon are picked up.

## Payroll and HR documents (HR phases 5–10)
* `DB_QUEUE_RETRY_AFTER=1900` must stay in `/etc/ricemill/ricemill.env` (above the 30-minute bulk-document job). Preflight blocks the deploy otherwise.
* `poppler-utils` (installed by `provision.sh`) gives PNG copies of documents; PDFs work without it.
* **Payroll settings**: EOBI, PESSI, the minimum wage and the s. 149 slabs are settings. Leave them at the defaults until the tax adviser confirms them for the current Finance Act.
* Restarting the workers during a deploy stops a running bulk-document batch; it continues where it stopped once the job is retried. Avoid deploying while a large payslip batch is running.
* Staff claims and labour-contractor bills (phase 10) exist as services and are tested, but have no screens yet.
