# Paper sizes, tokens, the weight page, lab corrections and negotiation

## Paper sizes
Every slip and report prints on one of four papers:
* A4
* A6
* Letter
* Small roll (80 mm)

**Choosing the paper**
* Use the **Paper** list at the top of every screen, next to your name.
* The choice is remembered on that device (in a browser cookie), because each PC has its own printer.
* **Paper: as designed** prints each document on its usual size.
* A print link can choose a paper for that one print by adding `?paper=a4|a6|letter|roll`.

**How each paper prints**
* **A4 and Letter:** wide reports stay landscape.
* **A6 and the roll:** a compact letterhead and smaller type; wide tables shrink to fit.
* **Roll:** the slip is cut just below its content. Instead of a page footer, the "printed by" line is at the end.

## Tokens (first come, first served)
* Each arriving vehicle gets the **next token of the day**. Tokens start again at 1 every day (Pakistan time).
* After **Vehicle arrived** is saved, a **Print token** button appears. It is also on the vehicle page.
* **The token slip** shows only the token number and the vehicle number. It prints on the small roll unless another paper is chosen.
* **Order:** the gate list has a Token column, and the Live weight and Negotiation pages list vehicles in token order.

## Live weight page
Each weighbridge is shown as an **analog dial**. The needle sweeps from 0 to the weighbridge's capacity, with tonnes marked, and glides to each new reading. The exact kg and maund are printed below it.

The ring shows the reading's state:
* **green:** stable;
* **amber:** moving;
* **grey:** no signal.

**Weighing takes one form:**
1. Type the **vehicle number**. If the vehicle is already inside, its party and kind fill in by themselves. Waiting vehicles can also be tapped from the token list.
2. Type the **party name** (suggestions come from the parties list).
3. Choose what it is:
   * **Inbound — paddy:** lab first. The vehicle is registered with a token and weighed only after the lab accepts the lot.
   * **Inbound — other goods:** weighed straight away, loaded weight then empty weight. No lab, no stock, no bill.
   * **Outbound:** weighed straight away, empty weight then loaded weight. A vehicle collecting a dispatch order continues that order's visit.
4. Press **Record weight** when the dial is stable. It records the weight the vehicle needs next and shows which one (loaded or empty).

**Notes**
* **New vehicle:** pressing Record weight registers the visit with the day's token.
* **New party:** someone who may manage masters can add one simply by typing its name. Gate operators choose from the list.
* **Paddy lot:** after its loaded weight, choose the godown it was unloaded into, then record the empty weight.

### Enter one weight manually
**Enter one weight manually** (next to Record weight) is for a weight taken on another weighbridge.

**Who:** Supervisor, Super Admin and Owner (the `weighment.manual` right).

**What to enter:**
* which weight;
* the kg;
* the reason (required);
* optionally, a photo or PDF of the other weighbridge's slip.

**What happens:**
* **No approval is needed;** the weight counts at once.
* **Flags:** it is marked **typed by hand**, plus "without slip evidence" when no slip was uploaded.
* **Weight checks:** it goes there for review.
* **Uploaded slip:** stored with its SHA-256 fingerprint and attached to the vehicle.
* **Only one of the two weights may be typed;** the other must come from this weighbridge.

The older Owner/Super Admin "manual weight" (with approval) on the vehicle page is unchanged.

## Lab tests per product and variety
**Gate & weighbridge → Lab tests** sets each test's standard and grace limits.
* **Per product:** a test can belong to one product. That product's own test replaces the general one with the same code.
* **Copy tests to a product / variety:** adds the general tests to a product, so its limits can then be edited.
* **Filter:** the list can be filtered by product.

## Correcting a lab result
On the vehicle page, **Record lab test** becomes **Correct lab result** once there is a result. It opens with the current figures filled in.

* **When:** corrections are possible until the loaded weight.
* **Reason:** required once the lab has accepted or rejected the lot.
* **History:** a correction never changes the old result. It records a new one, and the timeline shows **Lab result corrected** with the reason.
* **Decision:** the earlier accept or reject no longer counts. A corrected result above a grace limit rejects the lot at once; otherwise the lab decides again.

The vehicle page, the Negotiation page and the weight bill show the **lab report**: each test against its limits, the decision, and **Rejected because …** when rejected. A lot taken in after negotiation also shows why.

## Weight bill (weighment slip)
The slip shows:
* the vehicle, the party and the product;
* each weight, and whether it came from the weighbridge or was typed by hand with the reason;
* net and payable weight;
* the full lab report.

It shows **no price, rate cut or other amount**.

## Negotiation page
**Gate & weighbridge → Negotiation** lists the inbound vehicles whose price or Caat is open, in token order, with their current stage.

**For the chosen vehicle, the page shows:**
* weights and the lab report;
* a **timeline** of every step, each with time, person and reason:
  * the lab's decisions and corrections;
  * each price proposed, accepted or rejected (every version);
  * a rejected lot taken in;
  * each Caat proposal, its approval and any reopening.

**Buttons:** the same as on the vehicle page, following the same rules:
* Propose / Accept / Reject price;
* Accept rejected lot;
* Caat & price settlement;
* Approve settlement;
* Reopen settlement.

## Code
* **Paper:**
  * `app/Domain/Documents/PaperSize.php`;
  * `PdfRenderer::render()` (the roll is measured, then printed once more at that length);
  * `PaperSizeController`;
  * `resources/views/filament/hooks/paper-size.blade.php`.
* **Tokens:**
  * migration `2026_11_11_000100_gate_tokens`;
  * `GateService::arrive()`;
  * `TokenSlipController`;
  * `resources/views/gate/token-slip.blade.php`.
* **Weights:**
  * `app/Domain/Gate/LiveCapture.php`;
  * `app/Domain/Gate/ManualWeight.php`;
  * `app/Filament/Widgets/LiveWeightBoard.php` and its view.
* **Lab:**
  * `GateService::recordLabTest()` (the `lab_corrected` event);
  * `project()` and `hasEvent()` (decisions before a correction no longer count);
  * `app/Domain/Gate/LabReport.php`;
  * `ManageLabParameters` (copy tests to a product).
* **Negotiation:**
  * `app/Domain/Gate/NegotiationTimeline.php`;
  * `app/Filament/Support/NegotiationActions.php` (shared with the vehicle page);
  * `app/Filament/Pages/NegotiationPage.php`.
* **Simple weighing:**
  * `app/Domain/Gate/QuickWeigh.php`;
  * the `other` flows in `GateEntry::flow()`;
  * `resources/views/filament/widgets/partials/weight-dial.blade.php` (the dial).
* **Tests:**
  * `tests/Feature/Gate/EasyWeighingTest.php`;
  * `tests/Feature/Gate/LiveCaptureTest.php`.
