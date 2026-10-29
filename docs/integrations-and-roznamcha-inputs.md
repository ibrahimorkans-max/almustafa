# WhatsApp and Firebase from inside the system; roznamcha inputs

## WhatsApp (messages to parties)
* **A mill's own number (Administration → Integrations → Connect WhatsApp):**
  * **Who:** the Owner or the Super Admin.
  * **Where the settings come from:** Meta → WhatsApp Manager → API setup gives the **phone number ID** and a permanent **access token** (a system user token), plus the approved **template** name and language.
  * **Checked with Meta before saving:** the page then shows the number and business name it belongs to.
  * **Also on the page:** **Send a test message**, and **Disconnect**.
  * **Shortcut:** Masters → Party messages has a **Connect WhatsApp** button leading here.
* **The product's number (Platform → Integrations → Connect WhatsApp):** used by every mill that has not connected its own.
* **Which number sends** a party's message: the mill's own, else the product's, else the server's `.env` settings (as before).

## Firebase (notifications on party phones and browsers)
* **Who:** the product owner, for every mill (Platform → Integrations → Connect Firebase).
* **What to give:**
  * the **service account key** (.json, from Firebase → Project settings → Service accounts → Generate new private key);
  * the **web app** settings (General → Your apps);
  * the **Web Push key** (Cloud Messaging).
* **Checking:** the key is checked with Google on saving, and **Test Firebase** checks it again at any time.
* **The mill sees it as on or off** (Administration → Integrations).

## How the secrets are kept
* **Location:** WhatsApp tokens and the Firebase key are never stored in the database or shown again. Each connection is one file under `storage/app/private/integrations`, encrypted with the application key, and not in git.
* **Changing a key:** leaving the token or key field empty keeps the saved one.
* **The server's `.env` settings still work** when nothing is connected here.
* **Every connect and disconnect** is in the audit log.

## Roznamcha: each input works its own way (Accounts → Roznamcha → Add a page)
| Input | What happens |
|---|---|
| **Mobile camera** | **On a phone:** the picture field opens the camera. **On a computer:** **Use a phone's camera** shows a QR code and its link. Open it on any phone, take the photo and send it, and it appears on the computer's form by itself. The link is signed, works for 30 minutes, shows nothing of the mill's data and takes one photo. |
| **Scanner** | **Scan now** reads the page on the glass of the mill's network scanner (AirScan / eSCL: most HP, Canon, Epson and Brother network models). Set the scanner's address under Administration → Integrations → Scanner. Without one, scan with the scanner's own program and choose the saved file. |
| **PDF** | Only a PDF is accepted. |
| **Image** | Only a JPEG or PNG is accepted. |
| **Typed by hand** | The entries are typed on the form itself and saved with the page. |

Every scan or photo is kept unchanged (its SHA-256) and read by OCR as before.

* **Scanner limits:**
  * a browser cannot drive a scanner, so the server reads it. That works on the **mill server** (on the mill's network), or where `RICEMILL_SCANNER=true` and the server can reach the scanner;
  * only private network addresses are accepted (192.168.x.x, 10.x.x.x, 172.16–31.x.x); never the internet, this server or cloud metadata addresses;
  * USB-only scanners without network scanning are used through their own program (scan to a file).

## Tests
`tests/Feature/Fixes/IntegrationsAndRoznamchaInputTest.php` (7 tests).
