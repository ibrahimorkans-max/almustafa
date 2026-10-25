# Sign-in: only inside the mill, and the 6-digit code screen

## Only inside the mill
**Security → Sign-in rules → "Only inside the mill"**, set per mill by its Super Admin or the Owner.

When it's on, **nobody of that mill can sign in, or stay signed in,** unless their phone or computer is inside one of the mill's **sites**. Sites are set under HR → Sites: each has a centre (latitude and longitude) and a radius.

| Situation | Result |
|---|---|
| No position (location blocked in the browser or app) | Sign-in refused: "Sign-in needs your location…". The sign-in page asks the browser for it once and tries again by itself. |
| Outside every site | Refused: "You are outside the mill. Sign-in is allowed only inside the mill's premises." |
| Inside a site | Signs in. The position is kept on the session. |
| Signed in, then leaves the mill | The browser reports its position every 10 minutes. Outside, the session ends at once and the page goes back to sign-in. |
| Signed in, but no position for 20 minutes | The session ends ("Sign-in needs your location…"). |
| Mobile app or API | Sign-in must send `latitude`, `longitude` and `accuracy`, and every call must send an `X-Location: lat,lng,accuracy` header. Outside, or with no recent position, the call is refused (`reason: outside_area`). |

* **Who it applies to:**
  * every login of that mill: staff, Super Admin, parties;
  * an **Owner**, when any of their company's mills has it on (they may be at any of those mills' sites).
* **Who it doesn't apply to:**
  * the **product owner** (platform admin);
  * **registered gate agents**: computers fixed at the weighbridge, which have no GPS.
* **The allowance:** the device's own accuracy, up to 100 m (`RICEMILL_ATTENDANCE_MAX_ACCURACY_M`), is added to a site's radius.
* **So a mill can't lock itself out by mistake,** it can only be switched on when:
  * the mill has at least one site with a position;
  * the person switching it on is inside it at that moment, with location allowed in their browser.
* **If a mill is locked out anyway** (for example, the site was set in the wrong place): Platform → Customers → the customer → **Lift "Only inside the mill"**. That turns it off for all its mills, and is recorded in the audit log.
* **Every refusal is recorded** in the audit log (`auth.login.blocked`, with the position).
* **The browser:** it must be on **https** (or localhost) for the position to be read. Phones use GPS; computers use Wi-Fi positioning, which is less exact.

## The 6-digit code screen
After the password, accounts with two-step sign-in see **"Two-step verification"** and **six boxes**:
* typing a digit moves to the next box, and Backspace goes back;
* pasting a whole code fills all six;
* the **sixth digit sends the code at once**, with no button needed. If the code is right, you're in;
* a wrong code shows the error and empties the boxes for another try;
* **"Lost your phone? Use a recovery code"** switches to a box for a recovery code, and back;
* **"Back to sign-in"** starts again;
* the button below reads **"Verify & continue"**, for anyone who prefers to press it.

## Code
* `App\Domain\Security\SignInArea`;
* `LoginService::assertInsideArea()`;
* the setting `security.sign_in_area`;
* `SignInRules` (the switch), `EnsureWebAuthSession` (session checks), `SessionLocationController` and `public/js/session-location.js` (positions every 10 minutes), `ApiTokenGuard` (the `X-Location` header);
* the API `LoginRequest`;
* `ViewCustomer` (Lift);
* `Login` with `resources/views/filament/forms/otp-boxes.blade.php`, `code-links.blade.php` and `location-probe.blade.php`;
* tests: `tests/Feature/Security/SignInAreaTest.php` and `tests/Feature/Auth/WebAuthTest.php`.
