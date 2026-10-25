# MillNexa security audit, 25 October 2026

**Scope:**
* the Laravel/Filament web app, its API, the PostgreSQL row-level security, and the gate agent's sign-in;
* on the local development copy only.

**Method:**
* source-code review: pattern scans, then reading each hit;
* configuration review, plus dependency and git-history scans;
* read-only checks against the local server and database;
* new automated tests for each fix.

**Not done:** no attacks were run against a live server, no real data was touched, and nothing was changed outside this project.

**Labels:**
* **Confirmed**: shown by the code, and by a test or a live check.
* **Suspected**: a credible risk not proven.
* **Not tested**: outside what could be checked here.

## Summary table
| # | Vulnerability | Severity | Affected component | Recommended fix | Status |
|---|---|---|---|---|---|
| 1 | "Only inside the mill" could be skipped by sending a gate agent's device ID (browser cookie, or app header), from anywhere | **Medium** | `LoginService::assertInsideArea`, web `Login`, API `AuthController` | Excuse an agent only when its sign-in is signed with its secret; never a browser | **Fixed + test** |
| 2 | A change waiting for "Confirm" in the AI assistant could be altered in the browser before confirming | Low | `AssistantPage::$messages` | `#[Locked]` property | **Fixed + test** |
| 3 | `X-Powered-By: PHP/8.3.x` told visitors the exact PHP version | Low | All responses | Header removed; set `expose_php = Off` in production php.ini | **Fixed + test** |
| 4 | A position from the browser or phone can be faked by someone who already has a valid login (fake-GPS app, browser sensor override) | Medium | "Only inside the mill" | Combine with device approval ("Everyone" in Sign-in rules) and the IP allow-list for the mill's network | **Open: design limit** |
| 5 | Anyone who knows a username could lock that account (5 wrong passwords), and after 3 lockouts in 24 h only an admin could unlock it | Low–Medium | `LoginService` lockout | Lock out the **address** after 5 failures (with growing delays before that). Lock the whole **account** only after 30 failures from any mix of addresses. The admin rule applies to those account locks. | **Fixed + tests** |
| 6 | The Content-Security-Policy didn't limit where scripts could load from or where pages could send data | Low | `SecurityHeaders` | A full policy: only this site, plus Firebase (`gstatic`, `*.googleapis.com`); no framing, no plugins, no re-basing | **Fixed + test** |
| 7 | Plan limits (users, mills, vehicles) were counted without a lock, so two creations at the same moment could pass one over the limit | Low | `PlanLimits`, `GateService::arrive` | The customer row is locked (`FOR UPDATE`) inside the creating transaction; the vehicle check moved into its transaction | **Fixed + test** |
| 8 | Behind a reverse proxy or load balancer, every visitor would appear with the proxy's address, breaking the IP allow-list and per-address limits | Info → High if mis-deployed | Proxy trust | A `TRUSTED_PROXIES` setting: only those addresses may forward the visitor's IP, and `*` is refused. A deploy check shows the setting. | **Fixed + test (set it on the server if a proxy is used)** |
| 9 | On MariaDB there is no database-level isolation (row-level security is PostgreSQL only); only the application's checks separate customers | Info | Database choice | Use PostgreSQL for the multi-customer server (as now) | **Documented** |
| 10 | Main users approve their own work (payments included), so no second person checks | Accepted business risk | Approvals | Keep the audit log; review the "self-approved" report monthly | **Accepted (your decision)** |
| 11 | `.env.example` has `APP_DEBUG=true` | Low | Configuration template | The deploy check (`Preflight`) already blocks debug in production | **Mitigated** |

## Overall risk
**Low to moderate.** There are no Critical or High findings in the code. The design is strong:
* customers are kept apart twice: in the application, and in PostgreSQL with forced row-level security under a role that can't bypass it;
* sign-in protection is strong (see "Checked and found secure").

The one real bypass (finding 1) was in the newest feature and is fixed. Findings 5–8 have since been fixed too. What's left is the location limit (4), which is a limit of the design, and one business decision (10).

## Checked and found secure (with evidence)
| Area | What was checked | Result |
|---|---|---|
| SQL injection | Every `whereRaw`, `selectRaw`, `orderByRaw`, `DB::raw` and `unprepared` | User input is always a bound `?`. Inserted names (tables, columns) are fixed constants. |
| XSS | Every `{!! !!}`, `HtmlString` and `->html()` | All escape user text with `e()`. The rest is Blade's automatic escaping. |
| CSRF | Middleware and exemptions | On, with **no** exemptions. Cookies are `SameSite=Strict` and `HttpOnly`. |
| Command injection | `exec` / `system` / `Process` | Only `new Process([...])` with argument lists, never a shell string. |
| SSRF | Every outbound HTTP call | Addresses come only from configuration (WhatsApp, Firebase, the geocoder, Claude), never from users. |
| Path traversal | File storage and download | Paths are made by the server (content hash). Download is by record ID with a permission check. |
| File uploads | Attachments, receipts, logos, QR codes | Type is read from the **content**, from an allow-list (JPEG, PNG, PDF; CSV and text for imports); 5 MB cap; private disk; served with `nosniff`. |
| Insecure deserialization | `unserialize` | Not used. |
| IDOR / customer isolation | Record routes, prints, attachments, invoices, receipts | Each goes through the mill scope plus PostgreSQL row-level security. Invoices and receipts check the Owner's customer. Covered by `CustomerIsolationTest` and `BillingTest`. |
| Privilege escalation | Platform admin, roles | `is_platform_admin` can't be filled in from a form and is set only from the command line. Shared roles are changed only by the platform. Livewire page state is signed (HMAC), so the platform's screens can't be driven without access. |
| Passwords | Hashing | Argon2id. Unknown usernames are checked against a dummy hash (same timing). The message is the same for every refusal. |
| Two-step sign-in | TOTP, recovery codes | Codes are single-use (time step stored). Recovery codes are stored hashed. Required for Owners and Super Admins. |
| Sessions and tokens | Web and API | Sessions are encrypted, in the database, regenerated at sign-in, with idle and absolute limits. API tokens are stored hashed and last 15 minutes (5 for Owners). Agent requests are HMAC-signed with a single-use nonce. |
| Rate limiting | Sign-in, public pages | Web sign-in: 5 a minute. API sign-in: 20 a minute per IP, 5 per user and IP. Verify page: 30 a minute; shared links: 30 a minute. |
| Password reset | — | There's no self-service reset (only an admin can reset), so there are no reset-token attacks. |
| Secrets | The repository and its whole git history | No keys or passwords committed. `.env` is ignored, and the Firebase key is in private storage. |
| Dependencies | `composer audit` | **No known advisories.** |
| Database role | Live query | `ricemill`: not a superuser, no `BYPASSRLS`. Row-level security is really enforced. |
| Headers | Live response | `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy, COOP, `frame-ancestors 'none'`, HSTS on https. |
| Audit | — | Every sensitive action is logged in a hash-chained audit log. Sign-in alerts go to the Super Admin. |
| Backups | — | Encrypted (`BackupCrypto`), with an off-site copy. |

## Details of the fixed findings
### 1. Mill-area bypass with an agent's device ID (Medium, fixed)
* **What was wrong:** gate-agent computers (no GPS) were excused from "Only inside the mill" when the sign-in named their device ID. That ID isn't secret: it's in the agent's `agent.toml` and visible to administrators.
* **How it could be exploited:** someone with the password of an account that has a registered agent sets the browser cookie `rm_device`, or the app header `X-Device-Id`, to the agent's ID. They're then signed in from anywhere, and the mill-area rule is skipped.
* **How to reproduce (before the fix):** switch the rule on, register an agent for a gate operator, then sign in from outside with that cookie set. It went through.
* **Fix:**
  * a browser is never excused;
  * the app is excused only when the sign-in request carries a valid HMAC signature by that agent's secret. The secret is never shown again after registration, and each signature is single-use within 5 minutes;
  * the agent program (`agent/ricemill_agent/api.py`) now signs every request, sign-in included.
* **Check:** `SignInAreaTest`, "excuses a gate agent only when it signs its sign-in": a browser with the cookie is refused; the app unsigned is refused; the app signed is let in.
* **Not run here:** the agent's own Python tests, because Python isn't installed on this computer. Run `pytest` in `agent/` before giving out the new agent.

### 2. Assistant confirmation could be altered (Low, fixed)
* **What was wrong:** the waiting action lived in a public Livewire property, which the browser can change.
* **Impact:** limited. The action still runs with the user's own rights and the business rules, but what ran could differ from the card the person confirmed.
* **Fix:** `#[Locked]`.
* **Check:** `AssistantTest`, "runs exactly the change shown on the card": an attempt to change it is refused, and the original change runs.

### 3. PHP version in a header (Low, fixed)
* **Fix:** the header is removed in `SecurityHeaders`.
* **Check:** `WebAuthTest`, "sends security headers" (`assertHeaderMissing('X-Powered-By')`). A live `curl -I` of the sign-in page no longer shows it.

### 5. Lockout by a stranger (Low–Medium, fixed)
* **Before:** 5 wrong passwords from anywhere locked the whole account.
* **Now:**
  * **One address:** after the 3rd wrong try there's a growing pause (2 s, 4 s, 8 s), and after the 5th that address is locked out for 15 minutes for this account. The Super Admin is told ("Sign-ins blocked from one address"). The same person from the mill's own address still signs in.
  * **The whole account:** locked for 15 minutes only after **30** wrong tries from any mix of addresses since the last good sign-in (`RICEMILL_ACCOUNT_LOCK_AFTER`). After 3 such locks in 24 hours, an administrator must unlock it.
  * **Users → Unlock** also lifts address lock-outs.
* **Storage:** addresses are kept only as a keyed hash (`login_address_locks`).
* **Tests:**
  * `StolenDeviceTest`: growing pause, then address lock-out; the account still works from another address; the admin unlocks.
  * `ApiAuthTest`: address lock-out in the app; the account locks only after many addresses; wrong codes count the same way.

### 6. Content-Security-Policy (Low, fixed)
* **The policy:**
  `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.gstatic.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' https://*.googleapis.com https://www.gstatic.com; worker-src 'self' blob:; manifest-src 'self'; media-src 'self' blob:; frame-src 'none'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`
* **Why inline scripts and `unsafe-eval` stay allowed:** Filament, Livewire and Alpine need them. A nonce-based policy would need changes inside Filament, so it's left for later.
* **Checked:**
  * in the browser, the sign-in page loads with **no policy violations** (Alpine and Livewire start, images load);
  * `WebAuthTest` "hardening".
* **Still to do:** click through a few signed-in screens once (a chart, a file upload, a PDF preview, party notifications), with the browser console open, looking for "Refused to…" messages.

### 7. Plan limits under load (Low, fixed)
* `PlanLimits` locks the customer's row while counting, inside the transaction that creates the mill, login or vehicle. The vehicle check moved inside `GateService::arrive()`'s transaction.
* **Test:** `PlansTest` "locks the customer while it counts".

### 8. Proxy trust (deployment, fixed)
* Laravel's proxy middleware now trusts **only** `TRUSTED_PROXIES` (comma-separated addresses or ranges). An empty value, or `*`, trusts nobody.
* **When to set it:** only if a load balancer or proxy is in front of the app. With nginx/Apache and PHP on the same server, leave it empty.
* `ricemill:preflight` shows the setting and warns if `expose_php` is on.
* **Test:** `WebAuthTest`: a forged `X-Forwarded-For` header is ignored.

## Prioritised plan
1. **Before going live:**
   * configure `trustProxies` for your real proxy (8);
   * run the deploy check `php artisan ricemill:preflight --strict`, which checks debug off, https, secure cookies, the database role and backups;
   * set `expose_php = Off`;
   * use https with a modern TLS setup.
2. **Soon:**
   * decide the lockout rule (5);
   * turn on device approval wherever "Only inside the mill" is used (4);
   * tighten the CSP (6), testing every screen in a browser.
3. **Hardening:**
   * lock plan-limit checks (7);
   * run a dynamic scan (OWASP ZAP baseline) against a staging copy;
   * re-run `composer audit` monthly.

## Secure-coding rules for this code base
* **Database:** always pass user input as a bound value (`?`), never inside the SQL string.
* **Printing HTML:** in `HtmlString`, `->html()` or `{!! !!}`, wrap every piece of user text in `e()`.
* **Livewire:** any public property that carries a decision (IDs, pending actions, steps) is `#[Locked]`, or kept in the server session.
* **Locations and device IDs:** never trust them alone. Positions come from the client; device IDs are not secrets. Use signatures, approvals or network rules for real proof.
* **New tables with `entity_id`:** add them to `TenantTables::secure()`, so PostgreSQL enforces isolation too.
* **File uploads:** go through `FileStore` (content-sniffed type, size cap, private disk).

## Regression tests now in place
* `SignInAreaTest`: agent signature required; browser never excused; area enforced at sign-in, during a session, and for the app.
* `AssistantTest`: a locked confirmation.
* `WebAuthTest`: no version header; the code step; a wrong code empties the boxes.
* The existing suites stay in force:
  * `CustomerIsolationTest`, `PaymentFlowTest`, `BillingTest`, `RoleAccessTest`, `SuperAdminAccessTest`, `AuthorizationMatrixTest` (every screen for every role);
  * the sign-in, lockout and maintenance tests.

## Not tested here
* **Production:** the production server (open ports, firewall, TLS settings, OS updates, web-server configuration), because there's no access to it.
* **Dynamic scanning:** fuzzing and automated scanners (for example OWASP ZAP), and load or denial-of-service tests.
* **Other code:** the Flutter mobile app; the Python agent's tests (no Python here); JavaScript build dependencies (no lockfile, and the app ships prebuilt assets).
* **Backups:** restoring a backup on a separate machine.
