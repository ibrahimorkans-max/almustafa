# Where users are: exact area, city, region and country

**Where:** Security → **Sessions**. Everyone sees their own sessions. People with `session.view_all` (the mill's
Super Admin and the Owner) see everyone's. Use **Show → All** for the history.

## What is shown
* **Location (area, city, region, country).** For example: *Multan Road, Model Town, Lodhran, Punjab, Pakistan*.
  The map-pin link opens the spot on OpenStreetMap at street level.
* Under it:
  * **Exact (device GPS / Wi-Fi) ±12 m, 18 Oct 14:05**: how exact the position is and when it was taken;
  * **IP: Lahore, Punjab, PK**: the place of the internet address. When the two are far apart, look closer (VPN,
    shared account).
* Hover over the place to see the full postal address. The hidden columns **Full address** and **Coordinates** can be
  switched on from the table's column menu.
* If the person did not allow location in the browser, only the rough IP city is shown, marked *Rough, from the IP
  address*.

## Access review: everyone's location on one page
**Administration → Access review** has a **Location (area, city, region, country)** column for every account. It shows
the last exact position the person's browser shared in the last 90 days, or failing that the IP place of their last
sign-in.
* The place links to the map, and hovering shows the full address.
* Under the place:
  * **Exact (GPS / Wi-Fi) ±12 m, 18 Oct 2026 14:05**, or *Rough, from the IP address* / *Mill network*;
  * **IP: Lahore, Punjab, PK**.
* **● online now** under the last sign-in marks a session that was active in the last 15 minutes.
* The **Online now** button lists only the people working right now, with where they are.
* The CSV export has these columns:
  * online
  * location
  * location_detail
  * ip_location
  * coordinates
  * address

## How it works
1. After sign-in, the browser asks once: *"Allow this site to know your location?"*. On phones the position comes
   from GPS; on computers it comes from Wi-Fi. If the person refuses, only the IP city is recorded.
2. The position is sent when a page opens and again every 15 minutes while the system is open, so a session that moves
   (for example a phone) stays current.
3. The server turns the position into area, city, region and country:
   * **With internet:** OpenStreetMap's Nominatim service. At most one request a second, and each answer is cached
     for 30 days per ~10 m square.
   * **On the mill server without internet** (`RICEMILL_GEOCODER=off`): the nearest of the mill's own sites
     (HR → Sites), for example *At Main mill (56 m)*. A position far from every site keeps its coordinates and map
     link, and the place stays the IP's. For full addresses offline, run your own Nominatim server and set
     `RICEMILL_GEOCODER_URL`.
4. The IP's own city, region and country are kept separately, so both stay visible.

**Requirements:**
* The page must be opened over **https**, or on the server machine itself (localhost). Browsers refuse location on
  plain http over the network. The mill server uses its own https certificate.
* The security headers allow location for the system's own pages only: `Permissions-Policy: geolocation=(self)`.
  Before this change they blocked it completely, which is why only IP cities appeared.

**Settings** (`.env`):

| Setting | Meaning |
|---|---|
| `RICEMILL_GEOCODER` | `nominatim` (default) or `off` (offline: mill sites only) |
| `RICEMILL_GEOCODER_URL` | Nominatim reverse address. Default `https://nominatim.openstreetmap.org/reverse`; set it to your own server for offline use |
| `RICEMILL_GEOCODER_EMAIL` | Contact e-mail sent to Nominatim, as its usage policy asks |

**Privacy:** tell staff that the system records where they sign in from. The browser always asks first, and the
position is used only on the Sessions screen.

## Code
* `app/Domain/Security/ReverseGeocoder.php` turns a position into a place.
* `app/Http/Controllers/SessionLocationController.php` and `public/js/session-location.js`.
* Migration `2026_11_01_000100_add_exact_location_to_auth_sessions` adds these columns:
  * `area`
  * `address`
  * `country_name`
  * `ip_city`
  * `ip_region`
  * `ip_country`
  * `location_at`
* `app/Filament/Resources/AuthSessionResource.php` is the Sessions screen.
* `tests/Feature/Security/ExactLocationTest.php`.
