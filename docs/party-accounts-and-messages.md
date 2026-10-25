# Party accounts and messages to parties

## Party accounts
A supplier or buyer can have its own login and see **only its own records** on **My account**:

| Tab | What the party sees |
|---|---|
| Account | Where the account stands: *We owe you Rs …*, *You owe us Rs …* or *settled* |
| Vehicles | Date, token, vehicle, item, loaded / empty / net / payable weight, lab decision, status |
| Account entries | Each entry with the running balance |
| Bills | Purchase bills and sales invoices, with what is still open |

### Making a login
* **When adding a party:** tick **Make a login for this party and send the details**.
* **For an existing party:** open it and press **Make party login**. On a party that already has one, the same button gives a **new password**.

What happens:
* **Mobile number:** the party must have one; the details are sent to it.
* **Username and password:** the username is `p.<party code>`. The password is random, and the party chooses its own at the first sign-in.
* **Shown once:** the person making the login sees the password only at that moment. Without a message gateway, a **Send on WhatsApp** button opens WhatsApp with the message ready.
* **Stored record:** the login message is recorded without its text, so the password is never kept anywhere.

### What a party login can and cannot do
* After signing in, a party goes straight to **My account**. Every other screen is closed to it.
* It doesn't need an approved device, the same as employees, because it sees only its own records.

**Who can make logins and manage messages:** `messages.manage`. That's the Super Admin, the Owner, the Accountant and the Finance Manager.

## Messages to parties
Every message has the same layout: the mill's name and the subject in bold, one fact per line with the important values in bold (username, password, amount, balance, vehicle, token, weights), and **Thank you for working with us.** at the end. WhatsApp shows the bold; notifications and SMS show the same lines without it.

| Kind | When |
|---|---|
| Login details | A party login is made, or a new password is given |
| Money | Each entry with the party: purchase bill, payment made, payment received, sales invoice, debit or credit note, reversal. The message includes the balance after it. |
| Vehicle unloaded | An inbound vehicle is unloaded at the mill |
| Weights final | The slip is issued: loaded, empty, net and payable weight, and the item |

* **When they go:** a message goes only after the work is saved. An entry that fails or is undone sends nothing.
* **Each party:** the **Send messages to this party** switch on the party's page.
* **Each kind for the mill:** **Masters → Party messages** has a switch for each kind (saved at once), and the choice of **WhatsApp** or **SMS**.

### The Party messages list
Every message is listed with when, party, kind, number, text and how it went:

| Status | Meaning |
|---|---|
| **sent** | The gateway delivered it, or the office marked it sent |
| **to send by hand** | No gateway yet. **Send on WhatsApp** opens WhatsApp with the text; then press **Mark sent**. |
| **failed** | The gateway refused it. Send by hand the same way. |
| **skipped** | No mobile number, or messages are switched off for that party |

## Connecting WhatsApp and Firebase

With nothing connected, messages wait in **Party messages** for sending by hand. Each connection is optional and can be set up on its own. **Party messages** shows whether each one is connected.

### WhatsApp Business Cloud API (Meta)
Messages are sent from the mill's own WhatsApp Business number.

1. **Create a Meta Business account:** at business.facebook.com, then a **WhatsApp Business** app at developers.facebook.com. Add and verify the mill's phone number.
2. **Create a message template:** in **WhatsApp Manager → Message templates**.
   * Name: `mill_update`
   * Category: **Utility**
   * Language: **English**
   * Body (three lines):
     ```
     *Al Mustafa Rice Mills*
     {{1}}
     Thank you for working with us.
     ```
   * Sample for `{{1}}`: `*Payment made to you* · Amount: *Rs 3,68,053.00* · Entry: PAY/2026-27/0001 · 22 Oct 2026`
   
   Submit it for Meta's approval. Meta allows messages the business starts only through an approved template, and our text goes into `{{1}}`. A template that is only `{{1}}`, or starts or ends with it, is refused. A template variable can't contain line breaks, so through the template the lines of a message are joined with " · "; the bold still shows.
3. **Get a permanent token:** in **Business settings → System users**, create a system user and give it the WhatsApp app. Generate a **permanent access token** with `whatsapp_business_messaging`.
4. **Fill in the server's `.env`:**
   ```
   WHATSAPP_PHONE_NUMBER_ID=   # WhatsApp → API setup → Phone number ID
   WHATSAPP_ACCESS_TOKEN=      # the permanent token
   WHATSAPP_TEMPLATE=mill_update
   WHATSAPP_TEMPLATE_LANGUAGE=en
   ```
5. **Check it:** in **Party messages**, choose **WhatsApp** and press **Send a test WhatsApp** to your own number.

Notes:
* **New lines:** a WhatsApp template variable can't hold new lines, so each message is sent as one line.
* **If Meta refuses a message:** it's marked **failed** with Meta's reason, for example "template not approved". It can then be sent by hand.

### Firebase Cloud Messaging (notifications on phones and browsers)
A party signed in to **My account** presses **Get notifications on this device**. From then on, its messages also arrive as notifications on that phone or computer.

1. **Create a Firebase project:** at console.firebase.google.com.
2. **Add a Web app:** in **Project settings → General → Your apps → Add app → Web**. Copy its settings into `.env`:
   ```
   FIREBASE_API_KEY=
   FIREBASE_AUTH_DOMAIN=
   FIREBASE_PROJECT_ID=
   FIREBASE_MESSAGING_SENDER_ID=
   FIREBASE_APP_ID=
   ```
3. **Get the Web Push key:** in **Project settings → Cloud Messaging → Web Push certificates**, press **Generate key pair**. Copy the key into `FIREBASE_VAPID_KEY=`.
4. **Add the service account key:** in **Project settings → Service accounts**, press **Generate new private key**.
   * Save the file as `app/storage/app/private/firebase-service-account.json`.
   * Set `FIREBASE_CREDENTIALS=firebase-service-account.json`.
   * This folder is never served to browsers and never committed to git.

Notes:
* **HTTPS:** browser notifications need the site on HTTPS (or `localhost` for testing).
* **Dead devices:** a device Firebase no longer knows (permission withdrawn, app removed) is deleted automatically.
* **Switch:** **Party messages** has the switch **Also as a notification on the phones and browsers…**.

### Any other SMS or WhatsApp gateway
Instead of the above, any gateway that takes a JSON POST `{to, text, sender}` with a Bearer token can be used:
```
RICEMILL_SMS_URL=             # and/or RICEMILL_WHATSAPP_URL
RICEMILL_SMS_TOKEN=
RICEMILL_MESSAGE_SENDER=AlMustafa
RICEMILL_PORTAL_URL=https://erp.example.com/admin/login   # given in the login message
```

## Code
* `app/Domain/Messaging/PartyMessenger.php`: sending, the switches, the gateway, the outbox.
* `app/Domain/Messaging/Gateways/WhatsAppCloud.php`: the WhatsApp Business Cloud API, as an approved template.
* `app/Domain/Messaging/Gateways/FirebasePush.php`: Firebase Cloud Messaging (HTTP v1, signed service-account token).
* `PushTokenController` (a party registers a device) and `FirebaseServiceWorkerController` (`/firebase-messaging-sw.js`).
* `app/Domain/Messaging/PartyEventMessages.php`: the texts.
* `app/Domain/Messaging/PartyAccounts.php`: party logins.
* `app/Domain/Messaging/PartyStatement.php`: what a party sees.
* Hooks:
  * `Books::post()` (money);
  * `GateService::markUnloaded()` (unloaded);
  * `GateService::issueSlip()` (weights final).
* Screens:
  * `PartyMessagesPage`;
  * `PartyPortalPage` (My account);
  * `PartyLogin` on the party pages.
* Role `PARTY` (`portal.view_own`).
* Migration `2026_11_14_000100_party_accounts_and_messages`.
* Tests:
  * `tests/Feature/Messaging/PartyMessagesTest.php`;
  * `tests/Feature/Messaging/MessageGatewaysTest.php`.
