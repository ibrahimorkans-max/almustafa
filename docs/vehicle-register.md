# Vehicle register — vehicles in and out, weight and payable price

**Where:** Gate & weighbridge → **Vehicle register (in / out)**. Open to everyone who may see gate entries
(gate operator, supervisor, lab, managers, Super Admin, Owner).

## What the page shows
* A day picker (◀ today ▶) and three tabs: **Vehicles in** (paddy received), **Vehicles out** (dispatch), **Both**.
  A vehicle is on a day's register when it arrived **or** left that day (Pakistan time).
* Day totals for each side: vehicles, still inside, bags, net kg, payable / billed maund, amount to pay / to receive.
* One row per vehicle:
  * entry number;
  * vehicle number and type;
  * driver and phone;
  * party, product and sauda;
  * bags;
  * time in and time out;
  * **gross, tare, net, deduction (with the quality rate cut), payable** (kg and maund);
  * **rate per maund and amount**, marked Estimate, Purchase bill or Sales invoice;
  * status;
  * flags.

  Hover over an amount to see how it was worked out.
* **Complete details of a vehicle:** click the row, or its **Details** button, to open a side panel with:
  * **Vehicle and driver:**
    * vehicle number, type, owner and capacity, and whether it is blocked;
    * driver, phone, and licence number with its expiry date.
  * **Trip:**
    * entry number and direction;
    * supplier or buyer with their phone, product, and sauda with its rate;
    * dispatch order, bags, and the godown it was unloaded into or loaded from;
    * arrival and leaving times, time inside, and status.
  * **Weight and money:**
    * net, deduction and rate cut;
    * payable weight in kg and maund, rate per maund, and the amount;
    * the bill number and whether it is a draft or posted.
  * **Weighments:** each one with its time, scale, source (indicator or manual), who took it, and any re-weigh note.
  * **Lab test:** every figure, the grade and any remarks.
  * **How the weight was worked out** and **how the price was worked out**, step by step.
  * **Timeline:** every step of the visit, with its time and who did it.
  * **Flags.**

  The panel has a **Print this vehicle** button.
* **Print** on every row prints that vehicle's complete slip on one A4 page. It has everything above, with signature
  boxes for the operator, a **second person** and the driver. The ⋯ menu has the **official weighment slip** (once it
  is issued, with its verification code) and **Open gate entry**.
* **Print all vehicle slips (full details)** prints every vehicle of the day (on the tab you are on) in one file, one
  vehicle per page.
* **Print day register (all vehicles)** gives the end-of-day register (A4 landscape). It covers the tab you are on
  (in, out or both) and lists every vehicle with its weights, amounts and totals. It has signature boxes for the
  weighbridge operator, the gate keeper, a second person and the manager.
* **Excel** downloads the same register, one sheet per side.
* Every print and download is written to the audit log.

## How each vehicle's weight is worked out
| Step | Vehicle in (paddy) | Vehicle out (dispatch) |
|---|---|---|
| 1 | Loaded truck weighed on arrival → **gross** | Empty truck weighed on arrival → **tare** |
| 2 | Sample tested in the lab; lot accepted or rejected | Loaded from the godown |
| 3 | Unloaded into the godown | Loaded truck weighed before leaving → **gross** |
| 4 | Empty truck weighed → **tare** | |

* Weights come straight from the weighbridge indicator, and only a steady reading is accepted. A hand-typed weight
  needs a reason and a second person's approval, and it is marked **MANUAL** on the slip.
* If a vehicle is weighed again, the newest weighment of that kind replaces the old one. Both stay on record.
* **Net = gross − tare.**
* **Deduction** applies to vehicles in only. It comes from the lab decision, using the deduction rules frozen at that
  moment, so a later rule change never alters an old vehicle:
  * **Bag weight:** grams per bag × number of bags. For example, 400 bags × 600 g = 240 kg.
  * **Moisture / foreign matter over the free limit:** net × % over the limit × the rule's factor. For example,
    moisture 18.5 % with 17 % free gives 1.5 % of 15,250 kg = 228.75 kg.
  * **Above a reject limit:** the lot is rejected and nothing is bought. Accepting it anyway needs a written note.
* **Payable weight = net − deduction.** Stock receives the full net, which is what is physically in the godown. The
  deduction only changes what is paid.
* 1 maund = 40 kg.

Example: gross 25,480 − tare 10,230 = **net 15,250 kg**; deduction 240 + 228.75 = 468.75 kg; **payable 14,781.25 kg
= 369.53 maund**.

## How the payable price is set
1. The **rate per maund** is fixed in the sauda (deal) the vehicle comes against. It is agreed, approved, and cannot
   be changed at the gate.
2. **Gross amount = payable kg ÷ 40 × rate per maund.**
3. **Quality rate cut:** for each full 1 % that a lab figure is over its free limit, the rule's rupees per maund are
   cut. **Rate cut = payable kg ÷ 40 × cut per maund.**
4. **Net payable = gross − rate cut**, rounded to the rupee. The difference is shown as round-off.
5. When the weighment slip is issued, the **purchase bill** is made from these frozen figures:
   * income tax u/s 153 is withheld when it applies (filer or non-filer rate), which lowers what the supplier is paid;
   * a broker's brokerage (payable kg ÷ 40 × brokerage per maund) is booked separately; it is paid by the mill.
6. **Vehicle out:** value = net kg ÷ 40 × the sauda rate. The **sales invoice** adds sales tax, plus further tax for a
   buyer without an STRN.

Example, at Rs 1,000 per maund:
* 369.53 maund × Rs 1,000 = **Rs 3,69,531.25**;
* foreign matter 3.5 % with 2 % free is 1.5 % over, which counts as 1 full point × Rs 4 = Rs 4 per maund, so the rate cut is **Rs 1,478.13**;
* net payable = **Rs 3,68,053** (rounded).

On the page, **Estimate** means the bill is not made yet. Once the bill exists, its own figures are shown, marked
*draft* or *posted*. Those are the figures in the books.

## Code
* `app/Domain/Gate/VehicleRegister.php`: rows, price, weight steps, PDFs and Excel.
* `app/Filament/Pages/VehicleRegisterPage.php` and `resources/views/filament/pages/vehicle-register.blade.php`: the page.
* `app/Http/Controllers/VehicleRegisterPrintController.php` handles these routes:
  * `/admin/vehicle-register/vehicle/{id}`
  * `/admin/vehicle-register/weighment/{id}`
  * `/admin/vehicle-register/day/{date}?side=`
* `resources/views/gate/vehicle-slip.blade.php` and `day-register.blade.php`: the printed papers.
* `tests/Feature/Gate/VehicleRegisterTest.php`.
