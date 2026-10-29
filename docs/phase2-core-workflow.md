# Phase 2: the core mill workflow

Migration `2026_11_24_000100_core_mill_workflow`. It changes no existing figure: new tables, new nullable columns, and a code for each existing vehicle.

## Paddy varieties (Masters → Paddy varieties)
* **Fields:** name, code, category or type, description, unit, active or inactive.
* **The code** is made automatically (`PV-1121-0001`) and never changes, even when the variety is renamed.
* **Names are unique per mill,** ignoring case and spaces.
* **Varieties are deactivated, never deleted.**
* **Where they are chosen:**
  * a purchase Sauda, with **+** to add one on the spot. It picks the paddy product when exactly one belongs to that variety;
  * a paddy product;
  * the gate entry;
  * a paddy stock entry;
  * a deduction rule.

## Gate entry and token
* **The token is one continuous number per mill:** 000001, 000002 … The same series covers With Jack and Without Jack, and it never resets or reuses a number. It is issued under a locked counter (`DocumentNumbers::nextSerial`), so two entries at the same moment never share one.
* **Tokens issued before this change** keep the daily number printed on them. The day's queue order is still kept internally (`token_no`).
* **Type: With Jack / Without Jack** is required on the gate screen. It is stored and shown:
  * on the token beside the number (never inside it);
  * in the register, with a filter;
  * on Live Weight.
* **Party name:** start typing and registered parties are suggested as "Name (CODE)", by name or code. Any other name is kept as typed, with no Party record created and the party id left empty.
  * Live Weight's quick form follows the same rule. It used to create parties; it no longer does.
* **Date and time** come from the server, in Pakistan time, and are shown separately.
* **The token slip** shows:
  * the mill name;
  * "GATE ENTRY TOKEN";
  * the number, party, vehicle, type, date and time.
* **Token printing:** 80 mm or 50 mm roll, A4, or **Download token (PDF)**.
* **Token register (Gate):** search by token, party or vehicle. Filter by type, status and arrival dates.

## Live Weight
Waiting vehicles show the token, type and party (registered or typed). A vehicle registered on the Live Weight screen can be given its type there.

## Paddy stock entry (Stock & production → Paddy stock entry)
* **The figures:**
  * net = gross − tare;
  * deduction comes from the weight rules (fixed weight, % of net, bag weight) for the product and variety on that date, or is typed by hand **with a reason** (recorded in the audit log);
  * accepted = net − deduction. A deduction can never exceed the net;
  * amount = accepted (maund) × rate.
* **Draft or posted:** a draft changes no stock. **Post** adds the accepted weight to the godown, exactly once.
* **A gate token** can have one stock entry, and only while its weighbridge slip has not already booked the paddy. Once an entry is posted for a token, the slip does not book it again (flag "In stock through a paddy stock entry").

## Weight deduction rules (Gate → Deduction rules)
* **Fields:** name, automatic code (`DR-000001`), product, optional variety, type, basis, weight or percentage, effective date, condition and status.
* **New types:**
  * **Fixed weight per lot**;
  * **Percentage of net weight**.
* **A live example** shows the deduction on a 10,000 kg lot before saving.
* **A rule applies** from its effective date. A variety rule applies only to that variety.
* **Duplicate rules:** there can still be only one lab rule per product, test and variety. There can be several weight rules.

## Several production outputs
* **Production batch → Log several outputs** records head rice, broken, bran, husk and so on in one go. Either all are booked or none.
* **The batch page now shows** paddy fed, all outputs, and the **processing loss** (kg and %), alongside each output's recovery (output ÷ paddy × 100) against its standard.

## Automatic codes and the Code Directory
* **Automatic codes** are made for any record added without one:
  * parties `PTY-ALI-0001`;
  * products `PRD-RICE-0001`;
  * varieties `PV-…`;
  * vehicles `VEH-000001` (existing vehicles were numbered in the order they were added);
  * deduction rules `DR-000001`.
* **A code typed by hand is kept.** On an existing record the code cannot be changed.
* **Transaction numbers keep the existing central series** (`AMR/P/2026-27/0001`, `/S/`, `/STK/`, `/BNK/`, `/RZN/` …) from `DocumentNumbers`. No second numbering system was added. The gate token is the only plain number.
* **Masters → Code directory** lists every code of the working mill:
  * search by code or name, filter by module;
  * click to open the record;
  * export to Excel or PDF;
  * read-only.

## Tests
`tests/Feature/Fixes/Phase2CoreWorkflowTest.php` (10 tests).
