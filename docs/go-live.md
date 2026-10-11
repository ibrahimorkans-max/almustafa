# Go-live — Al Mustafa Rice Mills ERP

The steps from this repository to the mill using the system, in order. Details are in
[infra/vps/README.md](../infra/vps/README.md) (the server) and [deployment-checklist.md](deployment-checklist.md) (the mill).

| # | Step | Who | Done when |
|---|---|---|---|
| 1 | **Code checks.** Both test suites (PostgreSQL and MariaDB), Pint and Larastan pass on `main`. | Developer | All green |
| 2 | **Repository.** Create a *private* GitHub repository and push `main`: `git remote add origin <url>` then `git push -u origin main`. GitHub Actions then runs CI on every push. | Owner creates it; developer pushes | CI is green on GitHub |
| 3 | **Server.** A VPS with Ubuntu 24.04 (2 vCPU and 8 GB RAM is comfortable for one mill; 4 GB is the minimum), a domain or subdomain pointed at it, and SSH access. | Owner | `ssh root@<server>` works and the domain resolves to it |
| 4 | **Provision** once, as root: [README §2](../infra/vps/README.md). Installs PHP 8.3, PostgreSQL, nginx with HTTPS, the queue workers, the scheduler, the firewall and fail2ban, and writes `/etc/ricemill/ricemill.env` with fresh keys. | Developer with the owner | `provision.sh` ends without errors |
| 5 | **Production settings** in `/etc/ricemill/ricemill.env`: SMTP mail, the owner's e-mail for the audit anchor, the off-site backup bucket and keys, the office IP addresses for the Super Admin. Copy `RICEMILL_BACKUP_KEY` off the server (password manager or a sealed envelope with the owner). | Owner supplies; developer enters | `php artisan ricemill:preflight` shows no FAIL lines |
| 6 | **First deploy**: [README §3](../infra/vps/README.md), then the GitHub *Deploy* workflow secrets for later deploys ([README §4](../infra/vps/README.md)). | Developer | `https://<domain>/health` reports database and cache OK |
| 7 | **Mill set-up**: Super Admin, the AMR entity with NTN / STRN, the owner's login with 2FA, master data: [deployment-checklist.md](deployment-checklist.md). | Developer with the owner and accountant | Preflight still clean; the owner can sign in |
| 8 | **Tax adviser** confirms the rates for this Finance Act: further tax, withholding (s. 153), EOBI, PESSI, minimum wage, s. 149 salary slabs. Until then leave the defaults and withholding off. | Owner and tax adviser | Rates entered under Payroll settings and in the env file |
| 9 | **Trial run** on the server with test data, one working day per desk: gate and weighbridge (a truck in and out), lab, purchase bill and payment, milling batch, dispatch and invoice, attendance on the phones, a payroll with payslips, a staff claim, an expense and the profit and loss. Note anything wrong. | Each desk's user, with the developer | Every desk has done its day; problems are fixed |
| 10 | **Clean start**: drop the trial database and deploy to a fresh, empty one (never copy test or dev data), then redo step 7 with the real masters. Enter opening stock on go-live day and start profit and loss periods on or after that day. | Developer | Fresh database, real masters in |
| 11 | **Go live**: phones reload the app once; staff sign in and set their passwords and 2FA. For the first week, check the alerts and `/health` each morning (it also reports whether last night's backup ran). | Everyone | First week without blocking issues |

## Known limits at go-live
* Employees cannot submit their own expense claims yet; HR enters them.
* SMS has no provider: the share text is shown to HR to send from the HR phone. WhatsApp and e-mail work.
* The phone app and the gate agent were last changed in the earlier phases; reinstall or reload them on every device at go-live.
* A local `artisan serve` handles one request at a time, so dashboards load slowly in development; production runs php-fpm with several workers.
