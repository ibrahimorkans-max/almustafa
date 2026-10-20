# Lab-first procurement: lab tests, grace limits, negotiation, Caat and settlement

Stage 1 of the procurement requirements. It covers everything from the gate to the purchase bill.

## The order of a paddy vehicle
1. **Arrived** at the gate, with no weight.
2. **Lab test**, then the Lab Supervisor's decision: accept, or reject.
   * A result above its **grace limit** rejects the lot automatically, with the reason.
   * A rejected lot can come in only through **negotiation** (step 3).
3. **Negotiation** (only for a rejected lot):
   1. the Purchase Officer proposes a price and gives the reason;
   2. the Purchase Manager accepts it (never the person who proposed it);
   3. the Purchase Manager accepts the rejected lot, with the reason.
4. **Gross weight** (loaded). Nothing can be weighed before the lab decision.
5. **Unloaded** into a godown.
6. **Tare weight** (empty).
7. **Settlement:** the Purchase Officer enters the two Caats, and someone else (the Purchase Manager) approves.
8. **Slip:** the payable weight goes into stock and onto the sauda, and the purchase bill is drafted at the settled price.
9. **Exit.**

## Lab tests and limits
**Gate & weighbridge → Lab tests & limits.** The Lab Supervisor (`lab.limits`) and the Owner or Super Admin can
change them.

| Code | English | Urdu | Starting standard / grace |
|---|---|---|---|
| moisture | Moisture | نمی | 14 % / 16 % |
| green_grain | Green grain | گرین گراؤ | 3 % / 5 % |
| shell | Shell | چھلکا | 2 % / 3 % |
| shewad_green | Shewad green | شیواڈ گرین | 3 % / 5 % |
| broken | Broken | ٹوٹا ہوا دانہ | 5 % / 8 % |
| red_rice | Red rice | سرخ چاول | 2 % / 4 % |
| dandly | Dandly | ڈنڈلی | 1 % / 2 % |

**The starting limits are examples only. Set your own before use, and check the names and Urdu spellings.**

How a result reads against its limits:
* **Within the standard limit:** the lot waits for the Lab Supervisor's decision.
* **Above the standard but within grace:** it waits for the decision, with a grace warning shown on the screen.
* **Above the grace limit:** the lot is rejected automatically, and the reason lists each result over its limit.

A test can also be set for a single product, and it then replaces the general one for that product. The limits in
force when a result is recorded are saved with that result, so changing a limit later never changes past results.

Deduction rules (Gate → Quality deductions) can use the new test codes as well.

## Price versions (negotiation)
Each price is a new version (1, 2, 3…), and a version is never changed once it is decided.
* A first price below the sauda rate needs a reason.
* Every revised price needs a reason too.
* Rejecting a price needs a reason.
* The **last accepted** price is the one used, not simply the latest one proposed.
* The gate entry screen shows every version: price, status, reason, who proposed it and who decided it.
* Once the settlement is approved, the price can't change.

## The two Caats and the settlement
After the empty weight, the Purchase Officer enters:
* **Waste Caat (kg):** unusable material. It starts at the deduction the lab rules give, such as bag weight or excess
  moisture, and can be changed.
* **Caat per Maund (kg per maund):** for example, 1 kg per maund, multiplied by the net maunds (net kg ÷ 40).

| | Formula |
|---|---|
| Total Caat | Waste Caat + Caat per Maund × net maunds |
| Payable weight | Net weight − total Caat (must stay above zero) |
| Stock receives | the payable weight (the Caat is not kept) |
| Purchase bill | payable maunds × last accepted price |

**Price and quality rate cut:**
* At the **offered (sauda) price**, the lab's quality rate cut still applies on the bill, as before.
* At a **negotiated price**, the agreed price is final, with no extra cut.

**Approval:**
* Someone with `purchase.approve` approves the settlement. That can never be the person who proposed it, or the person
  who proposed its price.
* A price still waiting for a decision is accepted together with the settlement.
* If the weights change after the proposal, it must be proposed again.

**Stock valuation:** the bill's cost is spread over the kg that stock received, so stock is worth what was paid.

## Roles

| Role | Can |
|---|---|
| Lab Technician | record lab results |
| Lab Supervisor | record results, accept or reject within grace, set the lab tests and limits |
| Purchase Officer | propose prices and settlements |
| Purchase Manager | everything the Purchase Officer can, plus accept prices, accept rejected lots after negotiation, and approve settlements |
| Supervisor | as before, plus propose prices and settlements |
| Owner / Super Admin | everything (still never approving their own proposal) |

## Also updated
* **Assistant:**
  * `lab MNA-21-1234 moisture 13.5 broken 4 grade A`
  * `settle MNA-21-1234 waste 240 per maund 0.5`
  * `approve settlement MNA-21-1234`
* **Phone app sync** accepts the new results (`results: {code: basis points}`); the older fields still work.
* **Live weight** offers the gross weight only after the lab has accepted the lot.
* **Demo data** follows the new order.

## Rejected lot held at the mill (quarantine)
A rejected lot that stays at the mill while it is negotiated is recorded with **Hold rejected lot (quarantine)**,
together with where it is kept (for example, Shed 3).
* It carries the flag "Rejected lot held (quarantine)".
* It is **not stock** and is not billed.
* The flag goes away when the lot is accepted after negotiation, or when it leaves.

## Correcting an approved settlement
* **Before the slip:** a Purchase Manager can **reopen the settlement**, with the reason. The Caat and price can then
  be proposed and approved again (two people). The old proposal can't be approved again.
* **After the slip:** the stock receipt and the purchase bill exist, so a correction goes through the bill. That
  means a bill cancellation with approval, or a claim or debit note. The approved figures are never overwritten.

## Landed cost (freight, unloading, handling)
**Accounts → Expenses → New.**
1. Choose the category **Freight, unloading and handling (paddy)**.
2. Pick the vehicle under **For vehicle**.
3. Tick **Add to this lot's stock cost**.

The expense is posted to the books as usual. The amount is also added to the stock value of that lot:
* if the purchase bill is already posted, it is added at once;
* otherwise it is added when the bill is posted.

**Landed cost** = purchase value + those charges.

## Reports
**Administration → All reports → Procurement and lab.** Each report has PDF and Excel, and can be included in the
one-click PDF of all reports.

| Report | Shows |
|---|---|
| Vehicle settlements and landed cost | net, Waste Caat, per-maund Caat, total Caat, payable kg and maund, settled price, rate cut, purchase value, charges, landed cost, landed cost per payable maund, bill |
| Lab test results against the limits | every test of every vehicle with its result, standard and grace limits, reading, and the lab decision |
| Rejected lots | automatic or by the supervisor, what failed, where it is held, and what became of it |
| Negotiation history | every price version with its reason, who proposed and who decided |
| Caat analysis | by vehicle and by supplier, with Caat as % of net |
| Price variance | offered (sauda) against last accepted price, and the difference in Rs (the negotiated reduction; an analysis only, not booked as income) |

**Single-vehicle slip (Vehicle register → Print):** this is the final settlement report. It has these sections:
* vehicle and trip;
* every weighment;
* lab results against the limits;
* negotiation (every price version);
* Caat, purchase and financial settlement:
  * purchase bill, gross value, rate cut, income tax withheld;
  * net supplier liability, paid, outstanding;
  * charges, landed cost, stock received;
* approval history (prepared by, approved by, times);
* signature boxes: weighbridge operator, prepared by, checked by (second person), approved by.

## Dashboard
**Procurement this month** shows approved figures only, never drafts:
* purchase value (posted bills);
* suppliers owed;
* payments made;
* negotiated reduction;
* vehicles received;
* lab rejections;
* total Caat;
* settlements waiting.

It is shown to people with the dashboard plus ledger rights, or with purchase approval.

## Finance Manager role
Approves financial postings and payments, but never their own:
* books: ledgers, posting, voucher reversal, period lock;
* payments: create and approve, and the bank book;
* claims: approve settlement;
* tax and other reports, and the dashboard;
* purchase approval;
* daily wages payment.

It has no gate rights.

## Code
* `app/Domain/Gate/GateService.php`:
  * `recordLabTest`
  * `decideLab`
  * `proposePrice`
  * `decidePrice`
  * `acceptRejectedLot`
  * `proposeSettlement`
  * `approveSettlement`
  * `issueSlip`
  * `project`
* `app/Domain/Gate/LabStandards.php`.
* `app/Models/Gate/LabParameter.php` and `GatePriceVersion.php`.
* Migration `2026_11_06_000100_lab_first_procurement`.
* Screens:
  * `GateEntryResource` and its `ViewGateEntry` page;
  * `LabParameterResource`.
* `tests/Feature/Gate/LabFirstProcurementTest.php`.
