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
                'Weigh it on the weighbridge (first weight), unload or load, then weigh again (second weight).',
                'Open the entry to see each step; print the weighbridge slip when both weights are in.',
            ],
            'words' => 'vehicle arrival token truck gate in out',
        ],
        'LiveWeightPage' => [
            'how' => 'The weighbridge PC sends its reading to the ERP every moment. This screen shows it, and the weight is taken from here when a vehicle is weighed, so nobody types it.',
            'steps' => ['Open the screen to see the current weight and whether it is steady.', 'Weigh a vehicle from its gate entry: the steady reading is used.', 'If no reading comes, check Connect weighbridge.'],
        ],
        'ManualWeighingPage' => [
            'how' => 'When the indicator cannot be read (power, cable, a bike or small load), the weight is typed. Every typed weight is marked as manual and goes to Weight checks.',
            'steps' => ['Pick the vehicle or gate entry.', 'Type the first weight, and later the second weight.', 'Add a photo of the indicator or a note if you can.', 'Save: the weight is flagged for checking.'],
            'words' => 'typed weight by hand manual kanta',
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
            'how' => 'Weights that look wrong are held here: typed by hand, an unusual empty weight, no photo. A second person checks and clears or corrects them.',
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
            'how' => 'An authenticator app (Google or Microsoft Authenticator) gives a new 6-digit code every 30 seconds. It is your own second check at sign-in, so nobody else has to approve you. Recovery codes are spare one-time codes for when the phone is lost.',
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
