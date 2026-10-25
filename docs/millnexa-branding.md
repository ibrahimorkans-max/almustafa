# MillNexa: the product's branding

The system is **MillNexa: Smart Rice Mill Management**. There are two kinds of branding, and they don't mix.

| Where | Shows |
|---|---|
| **The app itself:** sign-in, the menu and top bar, the browser tab and icon, the phone home-screen icon, the sign-up page, the payment page, the "no access" and maintenance pages, the public document check (`/verify`), the closed-link page, the invoices the product owner sends customers, and passkey names | **MillNexa** logo, colours and name |
| **A mill's own documents:** slips, invoices, vouchers, reports, letters; emails and messages with them | **That mill's** name, logo and colours (Administration → Print & branding). Al Mustafa's mills keep the Al Mustafa brand. |

## Colours (from the logo)
| | Hex | Used for |
|---|---|---|
| Leaf green | `#217D25` | buttons, links, active menu item (the app's main colour) |
| Bright green | `#308C09` | the "Nexa" in the name |
| Gold | `#F2B705` | highlights and warnings (the ear of rice) |
| Charcoal | `#192327` | the "Mill" in the name |

## Files (`public/images/product/`)
| File | What |
|---|---|
| `millnexa-logo.png` / `-dark.png` | Mark and name side by side, on transparent; the dark-theme copy has a light "Mill" |
| `millnexa-logo-stacked.png` / `-dark.png` | Mark above the name and tagline |
| `millnexa-logo-print.jpg`, `millnexa-logo-stacked-print.jpg` | On white, for PDFs |
| `millnexa-mark.png` | The mark alone (leaf N, ear of rice, mill) |
| `favicon-32.png`, `favicon-48.png`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` | Browser and phone icons (the mark on a white rounded square) |
| `millnexa-app-icon.png` | The app icon as supplied |

Plus `public/manifest.webmanifest`, so "Add to home screen" on a phone shows MillNexa with its icon and green.

## Settings
* `config/ricemill.php → product`: name, tagline, files, colours. `PRODUCT_NAME` and `PRODUCT_TAGLINE` in `.env` change the words.
* `APP_NAME="MillNexa"`. Sign-in codes already in an authenticator app keep their old label; new enrolments show MillNexa.
* `config/ricemill.php → brand` is now only the fall-back for the first installation's (Al Mustafa's) printed documents.

## Fixed along the way
* Documents **emailed** or **shared by link** (payslips, letters) used to say "Al Mustafa Rice Mills" for every customer. They now carry the issuing mill's own name and phone.
* The public document check used to show Al Mustafa's address and phone to everyone. It now shows the product.
