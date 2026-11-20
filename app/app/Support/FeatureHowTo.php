<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How each screen works and how to use it, step by step: shown on the Features guide (under "How to use") and
 * given by the assistant when someone asks "how do I …". The purpose line is FeatureDescriptions; this adds
 * how it works ('how'), the steps ('steps') and extra words people may use for it ('words', for the assistant).
 * Keyed by the resource's or page's class name; the English text is translated like every other text.
 */
final class FeatureHowTo
{
    /** @var array<string, array{how: string, steps: list<string>, words?: string}> words: extra words; phrases of several words separated by commas */
    public const ALL = [
        // ── Gate & weighbridge ────────────────────────────────────────────────
        'GateEntryResource' => [
            'how' => 'Each vehicle gets one gate entry with a token. Paddy coming in goes: arrival → lab test → first weight → unloading → second weight → slip and purchase bill. Rice going out goes: arrival → first (empty) weight → loading → second weight → gate pass.',
            'steps' => [
                'Press "New gate entry" and type the vehicle number, driver and party (or the sauda).',
                'Choose in (paddy) or out (rice) and save: the token is printed for the driver.',
                'For paddy, the vehicle waits in the Lab queue until its test is entered.',
                'Weigh it on the weighbridge (first weight), unload or load, then weigh again (second weight). A weight it already has from another kanta can be typed at any step with "Enter a weight it already has".',
                'Open the entry to see each step; print the weighbridge slip when both weights are in.',
            ],
            'words' => 'vehicle arrival token truck gate in out',
        ],
        'LiveWeightPage' => [
            'how' => 'The weighbridge PC sends its reading to the ERP every moment. This screen shows it, and the weight is taken from here when a vehicle is weighed, so nobody types it.',
            'steps' => ['Open the screen to see the current weight and whether it is steady.', 'Weigh a vehicle from its gate entry: the steady reading is used.', 'If no reading comes, check Connect weighbridge, or press "Enter one weight manually" and type it (empty or loaded, a reason optional).'],
        ],
        'ManualWeighingPage' => [
            'how' => 'The Owner decides whether weights may be typed by hand, whether both of a vehicle\'s weights may be, and who may type them (by default everyone on the staff; a reason is optional). A weight the vehicle already has — its empty or loaded weight from another kanta\'s slip — can be typed at any step, even at arrival: if it is the next weight it counts at once; otherwise it is used by itself when the vehicle reaches that step, so the vehicle is weighed only once here. Every typed weight is marked "typed by hand" and goes to the weight checks.',
            'steps' => [
                'Owner: switch on "Allow weights to be typed by hand". Allow both weights only when the weighbridge is out of order.',
                'Tick the people who may type weights, or tick nobody to let everyone on the staff type them.',
                'At the weighbridge press "Enter one weight manually", or on the vehicle\'s page "Enter a weight it already has".',
                'Choose empty (tare) or loaded (gross) and type the kg. A reason and the slip photo are optional.',
            ],
            'words' => 'manual weight, typed weight, known weight, already weighed, other kanta, tare, gross, empty weight, loaded weight, kanta',
        ],
        'LabQueuePage' => [
            'how' => 'Paddy vehicles that arrived and are not yet tested wait here, oldest first. The lab result decides the deductions and whether the paddy is accepted.',
            'steps' => ['Pick a vehicle from the list.', 'Enter moisture, broken, chalky and the other tests.', 'Save: deductions are worked out from the deduction rules; a rejected lot goes to Negotiation.'],
            'words' => 'lab test moisture quality',
        ],
        'NegotiationPage' => [
            'how' => 'A lot the lab rejected or questioned can still be bought at a new price or deduction, agreed with the seller.',
            'steps' => ['Open the lot.', 'Type the agreed rate or extra deduction and the reason.', 'Accept (the vehicle goes on to weighing) or send it back.'],
        ],
        'WeighmentCheckResource' => [
            'how' => 'Weights that look wrong are held here: typed by hand (with or without a reason or slip), an unusual empty weight, no photo. A second person checks and clears or corrects them.',
            'steps' => ['Open a flagged weight.', 'Compare with the photo and the vehicle\'s usual empty weight.', 'Clear it, or correct it with a reason.'],
        ],
        'GatePassResource' => [
            'how' => 'A loaded vehicle may leave only with a gate pass, issued after its second weight and dispatch.',
            'steps' => ['Open the dispatched vehicle.', 'Issue the gate pass and print it for the driver.', 'The gate keeper checks the pass when the vehicle leaves.'],
        ],
        // ── Procurement ───────────────────────────────────────────────────────
        'SaudaResource' => [
            'how' => 'A sauda is a deal: party, variety, quantity, rate per maund and quality terms. Gate entries, quick bills and dispatches are linked to it, and the quantity left goes down with each delivery.',
            'steps' => ['Press "New sauda".', 'Choose purchase (paddy) or sale (rice), the party and the variety.', 'Type the quantity, the rate per maund and the terms; save.', 'Link vehicles, quick bills or dispatches to it; the screen shows how much is delivered and left.'],
            'words' => 'deal contract booking',
        ],
        // ── Stock & production ────────────────────────────────────────────────
        'StockBalanceResource' => [
            'how' => 'Worked out from every stock movement. Shows what is in each godown now.',
            'steps' => ['Filter by product or godown.', 'For movements over dates, use the Stock report.'],
        ],
        'StockReportPage' => [
            'how' => 'Reads the stock ledger, where every gate receipt, quick bill, dispatch, milling input and output, transfer and adjustment is written. For the dates you choose it shows opening, in, out and closing, in kg and maund (40 kg).',
            'steps' => ['Choose From and To dates.', 'Choose Paddy, Rice or everything, and a godown if you want one.', 'Tick "Each godown separately" to see godown by godown.', 'Each section has a total row at the bottom.'],
            'words' => 'stock report paddy rice opening closing godown maund',
        ],
        'StockEntryResource' => [
            'how' => 'Every movement of stock in and out of a godown: receipts, dispatches, quick bills, milling, transfers and count adjustments. It is a read-only record.',
            'steps' => ['Filter by product, godown or date to find a movement.', 'To move stock from one godown to another, use Requisitions and choose "To another godown (transfer)".'],
        ],
        'RequisitionResource' => [
            'how' => 'Stock leaves a godown only through an approved requisition: either to a production batch for milling, or to another godown (godown-to-godown transfer).',
            'steps' => ['Press "New requisition", choose the product, quantity and the godown it comes from.', 'Choose where it goes: a production batch, or another godown (transfer).', 'After approval the store issues it; the stock moves in both godowns.'],
            'words' => 'transfer, godown to godown, move stock, stock transfer, issue, store',
        ],
        'ProductionBatchResource' => [
            'how' => 'One milling run: the paddy fed in and the rice and by-products that came out. Weights can be worked out from the scale: weight on the scale − bags × bardana per bag − other deduction = net weight. The loss and outturn % are worked out for you.',
            'steps' => ['Press "New production batch" and pick the paddy variety.', 'Add the paddy input: godown, bags, weight on the scale, bardana per bag and any other deduction; the net weight fills itself.', 'When milling ends, add each output (head rice, broken, bran, husk …) the same way.', 'Close the batch: the outturn is compared with the standard and an alert is raised if it is low.'],
            'words' => 'milling batch outturn paddy to rice bardana deduction',
        ],
        // ── Sales & dispatch ──────────────────────────────────────────────────
        'QuickBillResource' => [
            'how' => 'One form for any bill made on the spot: a sale (even a few kg), a paddy purchase, rice for paddy (hisaab), milling of a customer\'s own paddy (mazdoori) or weighing an outside vehicle. Each line is net weight = weight − empty/bardana − bags × deduction per bag − other deduction, and amount = net ÷ 40 × rate per maund. Charges such as vehicle rent are added or taken off. It can be paid in full, in part or left unpaid, by cash, cheque, bank transfer, Raast, JazzCash/Easypaisa or other. The bill posts to the books and moves the stock at once.',
            'steps' => [
                'Press "New quick bill" and choose what it is for: Sale, Purchase (paddy), Rice for paddy, Milling or Weighing.',
                'Choose the party, or leave it empty and type the walk-in customer\'s name (a walk-in must pay in full now).',
                'Optional: pick the sauda; its rate and variety fill in and the sauda\'s quantity left goes down. For paddy, you may pick the gate entry after the lab.',
                'Vehicle: choose the type (truck, tractor, bike, by hand …), number and driver; for Weighing type both weights.',
                'Add a line for each product or variety: godown, bags, weight, empty/bardana, deduction per bag, other deduction and rate per maund. The price fills from the price list; change it if needed.',
                'Add charges: vehicle rent, labour, loading, commission — "Add" if the party pays it, "Less" if it is taken off.',
                'Payment: type what is paid now, how (cash, cheque …) and into which cash or bank account. Leave it empty for credit.',
                'Save and print. Later, open the bill and press "Receive payment" or "Pay the party" for the rest.',
                'A wrong bill: open it and press "Cancel this bill" with a reason. Money, stock and the sauda quantity are reversed.',
            ],
            'words' => 'bill, invoice, small sale, walk in customer, hisaab, rice for paddy, exchange, milling, mazdoori, weighing, outside vehicle, kanta, paid, unpaid, credit, cheque, multiple varieties, vehicle rent, expenses',
        ],
        'DispatchOrderResource' => [
            'how' => 'Rice sent to a buyer against a sale sauda. Two ways: "Vehicle at the weighbridge" (the normal way: order, then the vehicle is weighed empty and loaded at the gate) or "Direct" for a bike, by hand or a small quantity where the weight is typed — it makes the bill at once and marks the order dispatched.',
            'steps' => [
                'Press "New dispatch order" and choose how it leaves.',
                'Vehicle at the weighbridge: pick the sauda, the godown, quantity and bags; save. The vehicle then goes through the gate and is weighed.',
                'Direct: pick the sauda; the products and prices fill in. Type the vehicle type and number, date and time, weights, bags and deduction per bag; change the price if needed.',
                'Add vehicle rent or other charges, and the payment if any is made now.',
                'Save: the bill is made, the stock goes out and the sauda\'s quantity left goes down. The party\'s credit limit is checked first.',
            ],
            'words' => 'dispatch loading send rice buyer delivery direct bike by hand',
        ],
        'SalesBillResource' => [
            'how' => 'A full sales tax invoice for a buyer, made from dispatches. For small or on-the-spot bills use Quick bills.',
            'steps' => ['Press "New sales bill", pick the buyer and the dispatches.', 'Check rates and tax, then post it to the books and print.'],
        ],
        // ── Accounts ──────────────────────────────────────────────────────────
        'PaymentResource' => [
            'how' => 'Money the mill pays to a party: against their bills (oldest first) or on account, as an advance. A payment is requested, approved where the rules need it, then paid; the payee\'s bank details are frozen on it. Money received from a party is entered in Vouchers → "Receipt from party", or on the Quick bill.',
            'steps' => ['Press "New payment".', 'Choose the party, how (cash, cheque, IBFT, Raast, RTGS, pay order …) and the cash or bank account it is paid from.', 'Type the amount and tick the bills it settles; leave them unticked to pay an advance on account.', 'Save. When approved and paid, the payment voucher is posted and the party\'s balance changes.'],
            'words' => 'pay party payment advance cheque cash bank transfer',
        ],
        'VoucherResource' => [
            'how' => 'Every accounting entry is a voucher: payment, receipt, journal. Vouchers from bills, salaries and payments are made for you; here you also make an on-the-spot voucher by hand, such as money or an advance received from a party.',
            'steps' => ['Press "New entry" and choose the kind, e.g. "Receipt from party" or "Journal".', 'Receipt: choose the party, the cash or bank account it came into and the amount; tick the invoices it settles or leave them for an advance on account.', 'Journal: add the debit and credit lines (they must be equal) with a narration.', 'Post it. A posted voucher is reversed, never deleted.'],
            'words' => 'journal entry voucher party voucher on the spot receipt advance from party received money',
        ],
        'PurchaseBillResource' => [
            'how' => 'The purchase bill of a paddy vehicle bought through the gate: accepted weight after the lab deductions × rate.',
            'steps' => ['Open the vehicle\'s bill after its second weight.', 'Check the weight, deductions and rate; add a manual deduction if agreed.', 'Post it; pay it fully, partly or later from Payments.'],
        ],
        'ExpenseResource' => [
            'how' => 'Day-to-day expenses and other income, each posted to the account of its category.',
            'steps' => ['Press "New expense".', 'Choose the category, amount, how it was paid and add a note or photo.', 'Save: it posts to the books.'],
        ],
        'RoznamchaResource' => [
            'how' => 'The daily cash register (roznamcha), entered page by page with a photo of the paper page. The AI can read the photo and suggest the lines and accounts.',
            'steps' => ['Start the day\'s page and add the photo.', 'Check or type each line: who, what for, in or out and amount.', 'Post the page: each line becomes a voucher.'],
        ],
        'PartyTransactionsPage' => [
            'how' => 'All of one party\'s bills, payments and vouchers in date order with a running balance.',
            'steps' => ['Choose the party and the dates.', 'Open any row to see the bill or voucher.'],
        ],
        'PartyBalancesPage' => [
            'how' => 'Worked out from the books for every party: what they owe the mill (receivable) and what the mill owes them (payable), with the last money in and out and how many bills are unpaid.',
            'steps' => ['Search a party by name, code or phone.', 'Show: with a balance, only receivables, only payables or all parties.', 'Change "As on" to see balances on an earlier date.', 'Press Statement to open and send that party\'s statement.'],
            'words' => 'party balance receivable payable owes udhaar khata',
        ],
        'PartyStatementPage' => [
            'how' => 'A party\'s account for a period, ready to print or send on WhatsApp.',
            'steps' => ['Choose the party and dates.', 'Print it, download it or send it.'],
        ],
        // ── HR ────────────────────────────────────────────────────────────────
        'EmployeeResource' => [
            'how' => 'Each employee\'s details, job and salary. The salary typed on the new-employee form becomes the salary structure from the joining date.',
            'steps' => ['Press "New employee" and fill name, CNIC, department, designation and joining date.', 'Under salary choose Monthly or Daily and type the amount.', 'Save. Pay them with a salary voucher or the monthly payroll.'],
            'words' => 'staff employee add worker salary',
        ],
        'SalaryVoucherResource' => [
            'how' => 'Pays one employee\'s salary, bonus or other payment on the spot without running the whole payroll. It suggests the salary of the month, takes off an advance instalment or other deduction, posts the voucher and marks that month as paid so payroll does not pay it again.',
            'steps' => [
                'Press "New salary voucher" and choose the employee.',
                'Choose what you are paying (salary, bonus, other) and the month; the salary on record is shown.',
                'For a daily-wage employee type the days worked; the amount is worked out.',
                'Choose an advance or loan and the instalment to take off, and any other deduction (fine, canteen …).',
                'Choose how it is paid and from which cash or bank account; save and print the voucher.',
            ],
            'words' => 'salary pay employee voucher wages bonus tankhwah',
        ],
        'EmployeeBalancesPage' => [
            'how' => 'For the month chosen: each employee\'s salary, what was paid (salary vouchers and payroll), what is still due and their advance or loan balance.',
            'steps' => ['Choose the month.', 'Search an employee.', 'Press "New salary voucher" to pay someone who is still due.'],
            'words' => 'employee balance salary due advance loan',
        ],
        'AdvanceResource' => [
            'how' => 'A salary advance or loan given to an employee and recovered in instalments from salary vouchers or payroll.',
            'steps' => ['Press "New advance", choose the employee, amount and instalment.', 'Pay it out; instalments are taken off automatically.'],
        ],
        'PayrollRunResource' => [
            'how' => 'The monthly salary of all staff in one run: attendance, allowances, deductions, tax and advances. Months already paid on a salary voucher are left out.',
            'steps' => ['Start a run for the month.', 'Check each payslip; correct attendance or adjustments if needed.', 'Approve and pay; payslips can be printed or shared.'],
        ],
        'DailyWageSheetResource' => [
            'how' => 'Daily labour entered day by day per group, and paid by the day.',
            'steps' => ['Choose the date and the group.', 'Enter the workers and the work done; the pay is worked out from the rates.', 'Approve and pay.'],
        ],
        // ── Masters ───────────────────────────────────────────────────────────
        'PartyResource' => [
            'how' => 'Everyone the mill deals with: farmers, suppliers, buyers, brokers. A party\'s credit limit stops new dispatches when their balance is too high.',
            'steps' => ['Press "New party" and type name, type, phone and CNIC.', 'Set a credit limit and opening balance if any.'],
        ],
        'GodownResource' => [
            'how' => 'The godowns and silos where stock is kept. Each stock movement names its godown.',
            'steps' => ['Press "New godown", type its name and what it holds.'],
        ],
        // ── Administration & security ─────────────────────────────────────────
        'LoginAccountResource' => [
            'how' => 'A login for each person, with a role that decides what they see. The first sign-in is checked with an e-mail code.',
            'steps' => ['Press "New login account", choose the person and role, and give a temporary password.', 'Ask them to sign in: they confirm their e-mail and set their own password.'],
        ],
        'FirstSignInPage' => [
            'how' => 'The first time someone signs in, the ERP sends an e-mail with a 6-digit code and a 2-digit number. The 6-digit code is typed first; then the screen shows 4 numbers and the person taps the one in the e-mail — it moves on by itself. A wrong pick cancels both codes. Then they prove it is them with their own second check (passkey, authenticator app, recovery code or a code on the mobile app) if they have one. No other person needs to approve.',
            'steps' => ['Open your e-mail and type the 6-digit code.', 'Tap the 2-digit number that matches the e-mail.', 'Do the second check if asked, then set your password and details.'],
            'words' => 'first sign in login otp code e-mail number match',
        ],
        'NewNetworkPage' => [
            'how' => 'Signing in from a new network or a new device is checked the same way: 6-digit e-mail code, then tap the matching 2-digit number, then your own second check. Owner and Super Admin never wait for anyone\'s approval.',
            'steps' => ['Type the 6-digit code from the e-mail.', 'Tap the matching number.', 'Use your passkey, authenticator code, recovery code or mobile app code.'],
            'words' => 'new network new device wifi location sign in check',
        ],
        'TwoFactor' => [
            'how' => 'An authenticator app (Google or Microsoft Authenticator) gives a new 6-digit code every 30 seconds. It is your own second check at sign-in, so nobody else has to approve you. The same code also approves a new phone in the mobile app. Recovery codes are spare one-time codes for when the phone is lost.',
            'steps' => ['Open this screen and scan the QR code with the authenticator app.', 'Type the 6-digit code it shows to switch it on.', 'Save the recovery codes somewhere safe, on paper.'],
            'words' => 'authenticator two factor 2fa totp recovery codes google authenticator',
        ],
        'MyPasskeys' => [
            'how' => 'A passkey signs you in with the phone or computer\'s fingerprint, face or PIN. It cannot be phished and is the strongest second check.',
            'steps' => ['Press "Add a passkey".', 'Confirm with fingerprint, face or PIN on this device.', 'At a sign-in check choose "Use a passkey".'],
            'words' => 'passkey fingerprint face biometric',
        ],
        'ChangePassword' => [
            'how' => 'Change your own password. A forgotten password is reset from the sign-in screen with an e-mail code, the matching number and your second check.',
            'steps' => ['Type the current password and the new one twice.', 'Save; other sessions are signed out.'],
            'words' => 'password change forgot reset',
        ],
        'SecurityEmailPage' => [
            'how' => 'All sign-in codes go to this e-mail. Without a confirmed e-mail you cannot pass new-network checks or reset a forgotten password.',
            'steps' => ['Type your e-mail.', 'Type the code sent to it to confirm.'],
        ],
        'RoleResource' => [
            'how' => 'What each role may see and do. A change of permissions needs a second person\'s approval. The Owner or Super Admin can also make two-factor (an authenticator app) compulsory for everyone in a role.',
            'steps' => [
                'Open a role to see its permissions.',
                'Press "Request permission change", tick the permissions and give a reason; another person approves it.',
                'Press "Require 2FA for this role": its people set up an authenticator at their next screen. "Stop requiring 2FA" needs a reason.',
            ],
            'words' => 'role, permission, two factor, 2fa, authenticator, compulsory',
        ],
        'ApprovalRequestResource' => [
            'how' => 'Changes that need a second person (large payments, corrections, credit overrides) wait here until someone allowed approves or refuses.',
            'steps' => ['Open a request and read what will change.', 'Approve or refuse with a reason.'],
        ],
        'AssistantPage' => [
            'how' => 'Type what you want in English or Roman Urdu. The assistant finds records, opens screens, explains how a screen is used, and prepares changes that run only after you press Confirm.',
            'steps' => ['Type a question, e.g. "how do I make a quick bill" or "stock".', 'Or press a quick button and fill the blanks.', 'Check the card and press Confirm for any change.'],
            'words' => 'assistant chatbot help ai',
        ],
        'FeaturesGuide' => [
            'how' => 'Every screen you may open, by menu group, with its purpose and how to use it.',
            'steps' => ['Search a word, or open a group.', 'Press "How to use" on a card for the steps, or the card to open the screen.'],
        ],
        // ── The other screens ──
        'VehicleRegisterPage' => [
            'how' => 'Every vehicle in and out, day by day, from the gate entries: times, weights and party.',
            'steps' => [
                'Choose the dates.',
                'Open "Details" on a row, or print one vehicle or the whole register.',
                'For an official slip of a vehicle use "Official weighment slip".',
            ],
            'words' => 'register, in out, vehicles list',
        ],
        'ScaleResource' => [
            'how' => 'The weighbridges (scales) of the mill: capacity, smallest step and the stamping certificate. A weight above capacity is refused; an expired certificate is flagged.',
            'steps' => [
                'Press "New scale", type its code, name, capacity and resolution.',
                'Add the stamping certificate and its expiry date; renew it before it expires.',
            ],
            'words' => 'scale, weighbridge, kanta, certificate',
        ],
        'ConnectWeighbridge' => [
            'how' => 'The weighbridge PC sends its readings to the ERP. It is paired once with a one-time code, without typing any password.',
            'steps' => [
                'Make a pairing code here (or under Security → Devices → "Pair a weighbridge PC").',
                'On the weighbridge PC, type the code in the gate agent within 30 minutes.',
                'The live weight then shows on every screen.',
            ],
            'words' => 'connect weighbridge, pair, gate agent, live reading',
        ],
        'LabParameterResource' => [
            'how' => 'The lab tests done on paddy (moisture, broken, chalky …) with the standard and the grace limit of each, per product or variety. Above the grace limit a lot is rejected.',
            'steps' => [
                'Open a test and set its standard, grace limit and whether it is compulsory.',
                'Use "Copy tests to a product / variety" to give the same tests to another variety.',
            ],
            'words' => 'lab tests, limits, moisture, standard, grace',
        ],
        'DeductionRuleResource' => [
            'how' => 'How much weight or price is cut for each lab result above its standard, e.g. 1 kg per maund for each 1 % of extra moisture. The lab decision freezes the rules it used.',
            'steps' => [
                'Open a rule: the test, from which result it applies and the cut (kg per maund or rupees).',
                'New rules apply to lots decided after the change.',
            ],
            'words' => 'deduction, cut, kaat, moisture deduction',
        ],
        'LabTestEntryPage' => [
            'how' => 'The lab result of one paddy vehicle, with each result checked against its standard and grace limit as you type.',
            'steps' => [
                'Type each result; the screen says whether it is within standard, within grace or rejected.',
                'Choose accept or reject (when allowed) and save.',
            ],
            'words' => 'lab result, enter lab test',
        ],
        'QuoteResource' => [
            'how' => 'Price offers from sellers before a sauda: offer, counter-offer, accept or reject. An accepted quote becomes a sauda.',
            'steps' => [
                'Press "New quote": the seller, variety, quantity and the rate offered.',
                'Counter with your rate, or accept or reject.',
            ],
            'words' => 'quote, offer, negotiation, bhaao',
        ],
        'PaddyRequirementResource' => [
            'how' => 'How much paddy of each variety the mill plans to buy, and by when, compared with what is already bought.',
            'steps' => [
                'Press "New paddy requirement": variety, quantity and the period.',
                'Follow how much is covered by saudas.',
            ],
            'words' => 'paddy plan, requirement, purchase plan',
        ],
        'PaddyStockEntryResource' => [
            'how' => 'Paddy put into a godown outside the gate flow (e.g. opening stock or a transfer from another site): a draft first, then posted to stock.',
            'steps' => [
                'Press "New paddy stock entry": godown, variety, bags and weight.',
                'Check it and press "Post to stock", or "Cancel draft".',
            ],
            'words' => 'paddy stock, receive paddy, opening stock',
        ],
        'StockCountResource' => [
            'how' => 'A physical count of a godown. The difference from the books becomes an adjustment only after a second person approves.',
            'steps' => [
                'Press "Record physical count", choose the godown and type what is there.',
                'A second person approves; the stock is corrected with the reason.',
            ],
            'words' => 'stock count, physical count, stock taking',
        ],
        'OutturnStandardResource' => [
            'how' => 'The rice and by-products each paddy variety should give (e.g. 1121: 62 % head rice). A milling batch below it raises an alert.',
            'steps' => [
                'Open a variety and set the expected % of each output.',
            ],
            'words' => 'outturn, yield, recovery',
        ],
        'AlertResource' => [
            'how' => 'Warnings the system raises by itself: low outturn, odd weights, stock below zero, late lab results, payments. Each stays until someone looks at it.',
            'steps' => [
                'Open an alert, read why it was raised and open the record.',
                'Mark it handled when it is dealt with.',
            ],
            'words' => 'alerts, warnings',
        ],
        'ClaimResource' => [
            'how' => 'A quality or weight claim from a buyer or against a supplier, with its evidence, settled by a credit or debit note after approval.',
            'steps' => [
                'Press "New claim": party, the bill or dispatch and what is claimed.',
                'Add notes and evidence (photos, lab reports).',
                'Approve the settlement and post the note, or reject it.',
            ],
            'words' => 'claim, complaint, credit note, debit note',
        ],
        'PriceListResource' => [
            'how' => 'The selling price of each product from a date. Bills take the price of their date; a change of rate is requested and approved.',
            'steps' => [
                'Press "New price list" with its products and rates, or "Request rate change" on one.',
            ],
            'words' => 'price list, rates, selling price',
        ],
        'MarkupSettingResource' => [
            'how' => 'The margin added to cost when the system suggests a selling price. It is confidential: only allowed roles see it.',
            'steps' => [
                'Open a product group and set its margin %.',
            ],
            'words' => 'markup, margin, profit margin',
        ],
        'ExpenseCategoryResource' => [
            'how' => 'The kinds of expenses (diesel, electricity, repairs …) and the ledger each posts to.',
            'steps' => [
                'Press "New expense category", give its name and ledger.',
            ],
            'words' => 'expense category, expense head',
        ],
        'RecurringExpenseResource' => [
            'how' => 'Expenses that come every month (rent, internet, fixed salaries of guards …): a draft is made on its day to check and post.',
            'steps' => [
                'Press "New recurring expense": category, amount and day of the month.',
                'Each month check the draft and post it.',
            ],
            'words' => 'recurring, monthly expense, rent',
        ],
        'LedgerAccountResource' => [
            'how' => 'The chart of accounts: every ledger with its group and balance. System ledgers (cash, walk-in customer, salaries …) are made for you.',
            'steps' => [
                'Search a ledger and open it for its balance and entries.',
                'Use Books & reports for a printed ledger statement.',
            ],
            'words' => 'ledger, chart of accounts, khata',
        ],
        'PaperRecordResource' => [
            'how' => 'Old records from paper registers (balances, stock, purchases, sales, payments, vehicles) entered once, so the books start complete.',
            'steps' => [
                'Download the "Excel template", fill it and use "Import from Excel"; or "Add old record" one by one.',
                'Each record posts its voucher; "Cancel this record" reverses it.',
            ],
            'words' => 'old records, paper, opening balances, import excel',
        ],
        'BankAccountsPage' => [
            'how' => 'The mill\'s own bank accounts, used for payments, receipts and bank files.',
            'steps' => [
                'Press "Add bank account": bank, title, account number or IBAN.',
                'Use "Edit" to change one.',
            ],
            'words' => 'bank account, iban',
        ],
        'EmailConnectionResource' => [
            'how' => 'The mailbox where the bank sends its transaction alerts. The ERP reads the alerts to match money in and out with the books.',
            'steps' => [
                'Connect the mailbox (Gmail or Outlook) and choose the bank.',
                'Alerts then appear under Bank e-mail transactions.',
            ],
            'words' => 'bank email, mailbox, bank alerts',
        ],
        'BankEmailTransactionResource' => [
            'how' => 'Each bank alert read from e-mail: amount, date and sender, matched with a voucher or waiting to be matched.',
            'steps' => [
                'Open an unmatched alert and match it with its payment or receipt, or record it.',
            ],
            'words' => 'bank alert, bank transaction, match',
        ],
        'BankFileResource' => [
            'how' => 'A bulk transfer file for the bank (salaries, supplier payments) made from approved payments, to upload to internet banking.',
            'steps' => [
                'Press "Create bank file", choose the bank and the payments.',
                'Download the file and upload it to the bank.',
            ],
            'words' => 'bank file, bulk payment, salary transfer',
        ],
        'BankStatementLineResource' => [
            'how' => 'Bank reconciliation: each line of the bank statement matched with the books, so the bank balance agrees.',
            'steps' => [
                'Press "Import statement" and choose the bank\'s file.',
                'Match each line with its voucher; record bank charges or profit.',
            ],
            'words' => 'reconciliation, bank statement',
        ],
        'PeriodLockResource' => [
            'how' => 'A closed month is locked: nothing can be posted into it any more, so filed figures cannot change.',
            'steps' => [
                'Press "Lock a month" and choose the month.',
            ],
            'words' => 'period lock, close month, month end',
        ],
        'DepartmentResource' => [
            'how' => 'The departments of the mill (gate, mill floor, office …) used for staff and their costs.',
            'steps' => [
                'Add a department with its name.',
            ],
            'words' => 'department',
        ],
        'DesignationResource' => [
            'how' => 'Job titles given to employees (operator, supervisor, accountant …).',
            'steps' => [
                'Add a designation with its name.',
            ],
            'words' => 'designation, job title',
        ],
        'ShiftResource' => [
            'how' => 'Working shifts with their start and end, used to judge late arrival and overtime.',
            'steps' => [
                'Add a shift: name, start, end and grace minutes.',
            ],
            'words' => 'shift, timing, duty hours',
        ],
        'HolidayResource' => [
            'how' => 'Public and mill holidays; attendance and pay treat them as paid days off.',
            'steps' => [
                'Add a holiday with its date.',
            ],
            'words' => 'holiday, eid, leave day',
        ],
        'LeaveTypeResource' => [
            'how' => 'The kinds of leave (casual, sick, annual …) and how many days each allows in a year.',
            'steps' => [
                'Add a leave type with its yearly days.',
            ],
            'words' => 'leave type',
        ],
        'LeaveRequestResource' => [
            'how' => 'Leave applied for by an employee and approved or refused; approved leave shows in attendance and pay.',
            'steps' => [
                'Press "New leave request": employee, type and dates.',
                'The approver accepts or refuses it.',
            ],
            'words' => 'leave, chutti, apply leave',
        ],
        'AttendanceLogResource' => [
            'how' => 'Every attendance punch (phone, biometric file or by hand). A missing punch is requested and approved, never typed in silently.',
            'steps' => [
                'Use "Import from biometric file" for the machine\'s file.',
                'For a missed punch use "Request manual attendance"; the approver accepts or rejects it.',
            ],
            'words' => 'attendance, punch, biometric, hazri',
        ],
        'AttendanceRegisterPage' => [
            'how' => 'The month\'s attendance of all staff on one sheet: present, absent, late, leave and holidays.',
            'steps' => [
                'Choose the month and the department.',
                'Print it or download it.',
            ],
            'words' => 'attendance register, monthly attendance',
        ],
        'EmployeeDeviceResource' => [
            'how' => 'The phones employees use to mark attendance. A new phone waits for approval so nobody marks for someone else.',
            'steps' => [
                'Approve or block a waiting phone.',
            ],
            'words' => 'attendance phone, employee device',
        ],
        'SiteResource' => [
            'how' => 'The places (a GPS point and radius) where attendance may be marked, and where "Only inside the mill" sign-in is allowed.',
            'steps' => [
                'Add a site: name, position and radius in metres.',
            ],
            'words' => 'site, location, gps, geofence',
        ],
        'SalaryComponentResource' => [
            'how' => 'The pay heads used in salaries: basic, allowances and deductions, each with its ledger.',
            'steps' => [
                'Add a pay head: name, earning or deduction, and how it is worked out.',
            ],
            'words' => 'pay head, allowance, salary component',
        ],
        'AllowanceRuleResource' => [
            'how' => 'Rules that add an allowance by themselves, e.g. an attendance bonus for no absence in the month.',
            'steps' => [
                'Add a rule: the pay head, the condition and the amount.',
            ],
            'words' => 'allowance rule, attendance bonus',
        ],
        'PayAdjustmentResource' => [
            'how' => 'One-time bonuses, fines or other changes to one month\'s pay.',
            'steps' => [
                'Press "Add bonus or deduction": employee, month, amount and reason.',
                'Use "Festival bonus for all" for Eid and similar.',
            ],
            'words' => 'bonus, fine, deduction, eid bonus',
        ],
        'PayrollSettingsPage' => [
            'how' => 'The payroll rules and the government rates: income tax slabs, EOBI and social security.',
            'steps' => [
                'Press "Change" on a rate and give the date it applies from.',
            ],
            'words' => 'payroll settings, tax slabs, eobi',
        ],
        'ReimbursementResource' => [
            'how' => 'Money an employee spent for the mill (fuel, parts, travel) claimed back with the receipt and repaid after approval.',
            'steps' => [
                'Press "New staff claim": employee, amount, what for and the receipt.',
                'After approval it is paid or added to the salary.',
            ],
            'words' => 'staff claim, reimbursement, expense claim',
        ],
        'PieceWorkEntryResource' => [
            'how' => 'Work paid per piece (bags stitched, maunds loaded) with its pay from the rate.',
            'steps' => [
                'Add an entry: worker or group, work, quantity and date.',
            ],
            'words' => 'piece work, per bag, per maund',
        ],
        'ContractorRateResource' => [
            'how' => 'The rates paid to labour contractors per unit of work (per bag, per maund, per vehicle).',
            'steps' => [
                'Set the rate of each kind of work for a contractor.',
                'Use "Contractor vehicles" to link vehicles to a contractor.',
            ],
            'words' => 'contractor rate, labour contractor',
        ],
        'ContractorBillResource' => [
            'how' => 'The bill of a labour contractor made from the work recorded (vehicles unloaded, bags, maunds) at the agreed rates.',
            'steps' => [
                'Press "New contractor bill", choose the contractor and the period.',
                'Check the work, use "Assign workers" if needed, approve and pay.',
            ],
            'words' => 'contractor bill, labour bill',
        ],
        'DocumentTemplateResource' => [
            'how' => 'The layout of the HR documents the system prints: payslip, offer letter, experience certificate …',
            'steps' => [
                'Press "New template", choose the kind and write the text with its fields.',
            ],
            'words' => 'template, letter, payslip design',
        ],
        'DocumentBatchResource' => [
            'how' => 'Many HR documents made at once, e.g. all payslips of a month.',
            'steps' => [
                'Press "New bulk generation", choose the template, the month and the staff.',
            ],
            'words' => 'bulk documents, all payslips',
        ],
        'GeneratedDocumentResource' => [
            'how' => 'Every HR document the system made, to view, print or share again.',
            'steps' => [
                'Search by employee or kind and open it to print or share.',
            ],
            'words' => 'documents, payslips, letters',
        ],
        'MyDocumentsPage' => [
            'how' => 'Your own payslips and letters.',
            'steps' => [
                'Open one and download it.',
            ],
            'words' => 'my payslip, my documents',
        ],
        'DailyWageGroupResource' => [
            'how' => 'The labour groups paid by daily wage, each with its leader.',
            'steps' => [
                'Add a group with its leader and workers.',
            ],
            'words' => 'labour group, daily wage group',
        ],
        'DailyWageCategoryResource' => [
            'how' => 'The kinds of daily work (loading, cleaning, stitching …) and the rate of each.',
            'steps' => [
                'Add a kind of work with its daily rate.',
            ],
            'words' => 'work category, daily rate',
        ],
        'ProductResource' => [
            'how' => 'Everything the mill buys, makes and sells: paddy, rice, broken, bran, husk, bags. Each with its unit and category.',
            'steps' => [
                'Add a product: code, name, category and unit (kg or maund).',
            ],
            'words' => 'product, item',
        ],
        'PaddyVarietyResource' => [
            'how' => 'The paddy varieties the mill buys (1121, Super Basmati, 386 …). Lab tests and outturn standards are set per variety.',
            'steps' => [
                'Add a variety with its name.',
            ],
            'words' => 'variety, 1121, basmati',
        ],
        'VehicleResource' => [
            'how' => 'The vehicles that come to the mill, with owner and usual driver. Their usual empty weight is learnt to catch an odd tare.',
            'steps' => [
                'Add a vehicle: number, kind, owner and driver.',
            ],
            'words' => 'vehicle, truck, tractor',
        ],
        'DriverResource' => [
            'how' => 'The drivers who bring and take goods, with phone and CNIC.',
            'steps' => [
                'Add a driver with name, phone and CNIC.',
            ],
            'words' => 'driver',
        ],
        'BrandResource' => [
            'how' => 'The rice brands the mill packs and sells under.',
            'steps' => [
                'Add a brand with its name and logo.',
            ],
            'words' => 'brand',
        ],
        'TaxRateResource' => [
            'how' => 'The sales tax rates used on invoices, each from its date.',
            'steps' => [
                'Add a rate with its % and the date it applies from.',
            ],
            'words' => 'sales tax, gst rate',
        ],
        'CodeDirectoryPage' => [
            'how' => 'Every code the system made (parties, products, vouchers, vehicles …) in one list, to look one up and open its record.',
            'steps' => [
                'Search a code or name and open it.',
                'Download the list as Excel or PDF.',
            ],
            'words' => 'code, lookup, directory',
        ],
        'PartyMessagesPage' => [
            'how' => 'Which messages parties get by WhatsApp or SMS (each money entry with the balance, vehicle unloaded, weights final), and what was sent.',
            'steps' => [
                'Switch each kind of message on or off.',
                'Messages not sent by a gateway wait in the outbox: send them on WhatsApp and press "Mark sent".',
            ],
            'words' => 'party messages, whatsapp, sms',
        ],
        'ChatsPage' => [
            'how' => 'WhatsApp messages from parties, read and answered from the ERP.',
            'steps' => [
                'Open a chat and reply.',
            ],
            'words' => 'chats, whatsapp messages',
        ],
        'SyncConflictResource' => [
            'how' => 'Entries made offline on two devices that disagree. Nothing is overwritten silently: someone chooses.',
            'steps' => [
                'Open a conflict and choose "Apply device data" or "Keep server data".',
            ],
            'words' => 'sync, offline, conflict',
        ],
        'AccountsReports' => [
            'how' => 'The books: day book, cash book, bank book, ledgers, trial balance and party ledgers.',
            'steps' => [
                'Choose the report and the dates.',
                'Download it as PDF, or a ledger statement as PDF.',
            ],
            'words' => 'day book, cash book, trial balance, books',
        ],
        'FinancialReportsPage' => [
            'how' => 'Profit and loss, balance sheet and management figures for any period.',
            'steps' => [
                'Choose the period (e.g. "This tax year").',
                'Download as PDF or Excel.',
            ],
            'words' => 'profit and loss, balance sheet, munafa',
        ],
        'OperationalReportsPage' => [
            'how' => 'Reports on vehicles, weights, stock, milling and the lab for a period.',
            'steps' => [
                'Choose the report and the dates.',
                'Download as PDF or CSV.',
            ],
            'words' => 'operations report, weights report, lab report',
        ],
        'BusinessReportPage' => [
            'how' => 'One complete report of the whole business for a period: purchases, milling, sales, money, stock and staff.',
            'steps' => [
                'Choose the period and print or download it.',
            ],
            'words' => 'business report, complete report',
        ],
        'AllReportsPage' => [
            'how' => 'Every report of a period printed in one go, as one PDF.',
            'steps' => [
                'Choose the period and the reports, then print.',
            ],
            'words' => 'all reports, print all',
        ],
        'StatutoryReportsPage' => [
            'how' => 'Payroll returns for government: EOBI, social security and income tax deducted.',
            'steps' => [
                'Choose the month and the return.',
                'Download as PDF, Excel or CSV for filing.',
            ],
            'words' => 'eobi, social security, tax return, statutory',
        ],
        'SalesTaxReturnsPage' => [
            'how' => 'The sales tax and withholding tax registers for filing with FBR.',
            'steps' => [
                'Choose the month.',
                'Download the sales or withholding register as PDF or CSV.',
            ],
            'words' => 'sales tax return, fbr, withholding',
        ],
        'EntityResource' => [
            'how' => 'The firms and mills in this account. Each mill keeps its own books and records apart from the others.',
            'steps' => [
                'Press "New entity" to add a mill, or open one to change its details.',
            ],
            'words' => 'firm, mill, entity, company',
        ],
        'BrandingPage' => [
            'how' => 'The mill\'s name, logo, address and tax numbers printed on bills, slips and reports.',
            'steps' => [
                'Type the details and upload the logo.',
                'Use "Preview a document" to see a page before saving.',
            ],
            'words' => 'branding, logo, letterhead, print',
        ],
        'IntegrationsPage' => [
            'how' => 'The outside services the mill uses (WhatsApp, AI, notifications, scanner), and whether each works.',
            'steps' => [
                'Check each service; use "Send a test message" to try WhatsApp.',
            ],
            'words' => 'integrations, whatsapp, connected services',
        ],
        'SecurityCentre' => [
            'how' => 'The mill\'s security at a glance: risks to fix, and the emergency controls.',
            'steps' => [
                'Act on each risk shown.',
                'In an emergency use "Emergency lockdown" (lifted with "Lift lockdown"); plan downtime with "Schedule maintenance".',
            ],
            'words' => 'security, lockdown, maintenance',
        ],
        'AccessReviewPage' => [
            'how' => 'Who can do what: each person\'s role, permissions, two-factor and last sign-in, to remove access no longer needed.',
            'steps' => [
                'Look for dormant, never-used or locked accounts and act on them.',
                'Download the list as CSV (the download is audited).',
            ],
            'words' => 'access review, who can do what',
        ],
        'ArchiveSearch' => [
            'how' => 'The Office Desk: closed records moved off the working screens, found by dates and restored when needed.',
            'steps' => [
                'Choose the dates and the kind of record and press Show records.',
                'Open a record, or restore one or all of a period.',
            ],
            'words' => 'archive, office desk, old records, restore',
        ],
        'SubscriptionPage' => [
            'how' => 'Your plan, its limits, invoices and how to pay.',
            'steps' => [
                'See the plan and what is used.',
                'Open an invoice to pay it.',
            ],
            'words' => 'subscription, plan, invoice, pay',
        ],
        'ServiceUsagePage' => [
            'how' => 'How much of the paid services (messages, AI, SMS) the mill used, and their cost.',
            'steps' => [
                'Choose the month.',
            ],
            'words' => 'usage, messages used, ai usage',
        ],
        'AiIntegrationPage' => [
            'how' => 'The AI assistant\'s key. With it the assistant understands ordinary sentences and reads roznamcha photos; without it, short commands still work.',
            'steps' => [
                'Paste the key and save, then "Test the connection".',
                'Use "Remove the key" to switch the AI off.',
            ],
            'words' => 'ai key, assistant key',
        ],
        'SearchResultsPage' => [
            'how' => 'One search across every module: parties, vehicles, bills, vouchers, employees.',
            'steps' => [
                'Type in the search box at the top and open a result.',
            ],
            'words' => 'search, find',
        ],
        'WhatsAppWebhookPage' => [
            'how' => 'Settings for the mill\'s own WhatsApp number to receive messages from parties.',
            'steps' => [
                'Copy the webhook address and verify token into Meta, and save the app secret.',
            ],
            'words' => 'whatsapp webhook',
        ],
        'Dashboard' => [
            'how' => 'Today at a glance: vehicles inside, stock, money in and out, and alerts.',
            'steps' => [
                'Click a figure to open its screen.',
            ],
            'words' => 'dashboard, home, today',
        ],
        'PartyPortalPage' => [
            'how' => 'For a supplier or buyer: their own vehicles, weights, bills, payments and balance with the mill.',
            'steps' => [
                'Choose the dates to see the entries and download the statement.',
            ],
            'words' => 'party portal, my account',
        ],
        'DeviceResource' => [
            'how' => 'Every phone and computer that uses the ERP. A new one is approved by its owner\'s own e-mail code or authenticator code, or by an approver; a lost one can be revoked or wiped.',
            'steps' => [
                'Open a device to approve, revoke or wipe it.',
                'Use "Pair a weighbridge PC" to connect a weighbridge computer.',
            ],
            'words' => 'devices, phone approval, new device, wipe',
        ],
        'AuthSessionResource' => [
            'how' => 'Who is signed in, on which device and from where; any session can be signed out.',
            'steps' => [
                'Sign out a session, or "Sign out all my other sessions".',
            ],
            'words' => 'sessions, signed in, logout',
        ],
        'AuditLogResource' => [
            'how' => 'Who changed what and when. The log is chained, so a change to it would show; it cannot be edited.',
            'steps' => [
                'Filter by person, record or date.',
                'Use "verify" to check that the chain is intact.',
            ],
            'words' => 'audit log, history, who changed',
        ],
        'SignInAttempts' => [
            'how' => 'Failed and blocked sign-ins with the real reason of each (wrong password, new device, outside the mill …).',
            'steps' => [
                'Look for repeated failures and act on the account or address.',
            ],
            'words' => 'failed sign in, blocked login',
        ],
        'SignInRules' => [
            'how' => 'The mill\'s sign-in rules: whether new devices need approving (everyone, selected people or nobody), how many devices each person may use, and "Only inside the mill".',
            'steps' => [
                'Choose the rules and save; they apply from the next sign-in.',
            ],
            'words' => 'sign in rules, trusted devices, inside the mill',
        ],
        'ScreenProtection' => [
            'how' => 'Sensitive screens hidden from screenshots and screen sharing on phones and the browser.',
            'steps' => [
                'Tick the screens to protect.',
            ],
            'words' => 'screen protection, screenshot',
        ],
        'MyDevicesPage' => [
            'how' => 'The devices signed in to your own account. Remove any you no longer use or do not recognise.',
            'steps' => [
                'Press "Remove" on an old device.',
            ],
            'words' => 'my devices',
        ],
        'SignInApprovalPage' => [
            'how' => 'Approve a sign-in waiting for you: scan the QR code on the other screen and pick the number it shows.',
            'steps' => [
                'Scan the QR code with this phone and pick the matching number.',
            ],
            'words' => 'approve sign in, qr',
        ],
        'DuressPin' => [
            'how' => 'A secret PIN to type instead of your password if you are ever forced to sign in: it signs out everything and silently alerts the owners.',
            'steps' => [
                'Set the PIN (different from your password) and keep it secret.',
            ],
            'words' => 'duress pin, forced login, panic',
        ],
        'PlatformOverview' => [
            'how' => 'For the SaaS owner: all customers, income and usage of the platform.',
            'steps' => [
                'Open a customer, or add one with "New customer".',
            ],
            'words' => 'platform overview, saas',
        ],
        'CustomerResource' => [
            'how' => 'The companies using the platform: their mills, plan, trial and access.',
            'steps' => [
                'Press "New customer", or open one to "Add a mill", "Set trial period" or give lifetime access.',
            ],
            'words' => 'customer, company, saas customer',
        ],
        'PlanResource' => [
            'how' => 'The subscription plans: price and limits (mills, users, messages).',
            'steps' => [
                'Open a plan and set its price and limits.',
            ],
            'words' => 'plan, pricing',
        ],
        'SaasInvoiceResource' => [
            'how' => 'The invoices of customers and whether they are paid.',
            'steps' => [
                'Issue the month\'s invoices, or a "New invoice" by hand.',
                'Mark a payment when it comes.',
            ],
            'words' => 'saas invoice, billing',
        ],
        'PaymentMethodResource' => [
            'how' => 'The ways customers can pay (bank transfer, JazzCash, Easypaisa …) shown on their payment page.',
            'steps' => [
                'Add a method with its account details.',
            ],
            'words' => 'payment method',
        ],
        'DataVisibilityRuleResource' => [
            'how' => 'How far back a customer can see its records, by plan.',
            'steps' => [
                'Set the months of history each plan shows.',
            ],
            'words' => 'data visibility, history limit',
        ],
        'BankEmailTemplateResource' => [
            'how' => 'How each bank\'s alert e-mails are read (amount, date, sender), so bank alerts work for every mill.',
            'steps' => [
                'Open a bank\'s template and test it on a sample e-mail.',
            ],
            'words' => 'bank email template',
        ],
        'PlatformUsersPage' => [
            'how' => 'Every user of every company and mill: role, access, password and sign-ins.',
            'steps' => [
                'Search a user and change the role or access, reset the password or see sign-ins.',
                'Use "Add a user" for a new one.',
            ],
            'words' => 'all users, platform users',
        ],
        'PlatformIntegrationsPage' => [
            'how' => 'The platform\'s connections to WhatsApp, AI and push notifications, used by every mill.',
            'steps' => [
                'Use "Connect WhatsApp" or "Connect Firebase", then test it.',
            ],
            'words' => 'platform integrations, firebase, whatsapp',
        ],
        'EmailIntegrationPage' => [
            'how' => 'Gmail and Outlook sign-in for reading bank e-mails.',
            'steps' => [
                'Enter the app details from Google or Microsoft and save.',
            ],
            'words' => 'gmail, outlook, email integration',
        ],
        'SenderIdentitiesPage' => [
            'how' => 'The name and number each company or mill sends its messages under.',
            'steps' => [
                'Press "Set" on a mill and choose its WhatsApp number, SMS sender and e-mail.',
            ],
            'words' => 'sender identity, sender name',
        ],
        'ServicePricesPage' => [
            'how' => 'The price of each paid service and the monthly bills for what customers used.',
            'steps' => [
                'Use "Add a price", then "Issue monthly statements".',
            ],
            'words' => 'service prices, billing',
        ],
        'UsageCostsPage' => [
            'how' => 'What API calls, SMS, WhatsApp and e-mail cost the platform.',
            'steps' => [
                'Choose the month.',
            ],
            'words' => 'costs, api cost',
        ],
        'SecuritySettings' => [
            'how' => 'Platform-wide security rules for every mill (passwords, sessions, sign-in checks).',
            'steps' => [
                'Change a rule and save.',
            ],
            'words' => 'security settings, platform security',
        ],
        'WeighmentModesPage' => [
            'how' => 'Which weighing and lab modes each company or mill may use (weigh first, lab first, no lab …), with the history of changes.',
            'steps' => [
                'Press "Set modes" on a company or mill, or "Lab features" for its lab options.',
            ],
            'words' => 'weighment modes, lab first, weigh first',
        ],
    ];

    /** @return array{how: string, steps: list<string>}|null in the chosen language */
    public static function for(string $class): ?array
    {
        $entry = self::ALL[class_basename($class)] ?? null;

        return $entry === null ? null : ['how' => Locales::word($entry['how']), 'steps' => array_map(Locales::word(...), $entry['steps'])];
    }

    /** Extra words a person may use for the screen (for the assistant's search). */
    public static function words(string $class): string
    {
        return self::ALL[class_basename($class)]['words'] ?? '';
    }
}
