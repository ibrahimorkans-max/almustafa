# Unloading contractors, rejected paddy and vehicle-number corrections

Migrations `2026_11_27_000100_contractor_vehicle_rates`, `2026_11_27_000200_gate_partial_rejection` and `2026_11_28_000100_gate_rejection_by_weight`.

## Labour contractors paid per vehicle
* **Rates (HR → Contractor rates):** set what each contractor (a vendor party) is paid:
  * work: unloading or loading;
  * basis: **per vehicle**, **per bag**, **per ton** or **per maund** of net weight;
  * optionally for one kind of vehicle (truck, tractor …). A rate for that kind wins over "any vehicle";
  * from an effective date. A new rate is a new row, so earlier vehicles keep the rate of their day.
* **Linking vehicles to a contractor:**
  * **When unloading:** gate entry → **Mark unloaded** → "Unloaded by (labour contractor)".
  * **Afterwards, one vehicle:** **Unloading contractor** on the gate entry.
  * **Many vehicles at once:** on the gate list, tick the vehicles → **Assign labour contractor**.
  * The gate list has a **Contractor** column, and filters for the contractor and for "Not billed yet / Billed". **Vehicles** on a rate opens that contractor's vehicles.
* **Billing (HR → Contractor bills → Prepare bill → basis "Vehicles unloaded / loaded"):**
  * every vehicle of the contractor in the period that is not billed yet becomes one line: token, vehicle, date, quantity (1, bags, tons or maunds), rate and amount;
  * the form previews how many vehicles and how much before saving;
  * sales tax and withholding work as on other contractor bills.
* **No vehicle is ever billed twice:**
  * each vehicle is marked with its bill;
  * a billed vehicle cannot be moved to another contractor;
  * a refused bill frees its vehicles.
* A vehicle with no matching rate stops the bill with a clear message (set the rate first).

## Paddy rejected while unloading
* **Gate entry → Mark unloaded → "Some paddy rejected while unloading":**
  * **Rejected weight (kg):** required unless only bags are counted;
  * **Rejected bags:** optional;
  * **the reason;**
  * **where the rejected paddy is at the empty weighing:**
    * **Still on the vehicle** (usual): the empty weight carries it, so **net = gross − empty weight = only the paddy unloaded**, shown by itself once the vehicle is weighed empty;
    * **Taken off and kept aside:** the vehicle is weighed truly empty and the rejected weight is subtracted, so **net = gross − empty weight − rejected kg**.
* **Either way, the net is only the paddy accepted.** Payable weight, the stock receipt, the purchase bill and the sauda delivery all follow from it. Per-bag deductions count only the bags unloaded.
* **The usual-tare check:**
  * an empty weight still carrying rejected paddy raises no "tare differs from usual" alarm, and is left out of the vehicle's usual-tare history;
  * a truly empty weight (paddy taken off) is checked as usual.
* **The rejection is recorded:**
  * on the record ("Rejected while unloading: 2,000 kg: on the vehicle at the empty weight …", with the reason);
  * in the timeline and the flags;
  * on the weighment slip.
* **Limits:** rejection is possible only before the empty weight. A rejected weight cannot be more than the loaded vehicle weighs. All bags rejected means rejecting the lot instead.

## The gate entry page
Its many buttons are small, so they fit on one line, also on a phone.

## Correcting a vehicle number after weighing
* **Gate entry → Correct vehicle number:** enter the right number and the reason.
* **The weights stay.** The visit moves to the right vehicle (and its kind). The record is flagged "Vehicle number corrected", and the old and new numbers and the reason are kept in the timeline and the audit log.
* **Who may correct:**
  * gate staff, until the slip is issued;
  * after the slip is issued or the vehicle has left, only the Owner or the Super Admin.
* **Blocked vehicles** cannot be put on a visit this way.

## Tests
`tests/Feature/Fixes/ContractorVehiclesAndUnloadingTest.php` (9 tests).
