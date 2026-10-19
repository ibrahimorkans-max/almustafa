# Daily wages (casual labour)

Casual workers are paid by the day or by the piece without registering them as employees: no CNIC,
no login, no attendance device. They usually come as a **labour group** under one person (the mukadam
or jamadar), who brings them and takes the group's pay.

## Screens (menu **Daily wages**)

| Screen | What it is |
|---|---|
| **Daily wages** | One wage sheet per day of work of a group (or of one worker). The list shows today's and this month's total and what is still owed; filters by status, group, work and dates; a total at the bottom; **Book**, **Pay**, **Print** on each row |
| **Labour groups** | Group name, leader (mukadam), leader's phone, CNIC (optional), usual work and usual number of workers, and what the group was paid this month. Workers are not listed |
| **Work categories** | Kinds of work and their rate: per person per **day** or **hour**, or per **bag**, **maund**, **ton**, **trip** or **piece** |

## Entering a day's wages

1. **Daily wages → Enter daily wages.**
2. Work date; **A labour group** (choose it: its usual work, rate and number of workers are filled in) or **One worker** (type the name).
3. Work, number of workers, quantity (days each for day / hour work; bags, maund… for piece work), rate.
4. Optional: **Extra** (overtime, bonus, tea money) and **Less** (advance already given, food); workers' names; what was done.
5. The **To pay** line shows the result as you type: day work = workers × days × rate; piece work = quantity × rate for the whole group.
6. **Payment**: save as draft, **paid now in cash**, **paid now from the bank** (choose the account), or **book now, pay later**. A **Print** link to the wage slip appears after saving.

## Finance

| Step | Books |
|---|---|
| Booked and paid now | Dr **Daily wages (casual labour)** (EXP-DAILY-WAGES, direct expenses) · Cr cash or bank |
| Booked, pay later | Dr Daily wages · Cr **Daily wages payable** (DW-PAYABLE, current liabilities) |
| Paid later | Dr Daily wages payable · Cr cash or bank |

So daily wages appear by themselves in profit and loss (direct expenses, cost of goods), cash flow, the cash
book and bank book, ledgers and the balance sheet. Also:

* **Complete business report**: a key figure (paid, worker-days, still owed) and a "Daily wages (casual labour)" section;
* **Dashboard** (Owner / Super Admin): "Daily wages" this month and what is still owed to groups;
* **Assistant**: "profit this month" shows daily wages; *wages 12 workers loading group Akram* (or
  *wages 4 workers stitching under Rafiq 800 bags pay later*) enters and books a sheet after Confirm.

A booked sheet is not edited; a mistake is corrected with a reversal voucher. Only drafts can be cancelled.

## Printed wage slip

A5 slip: group or worker, work, workers, quantity, rate, extra, less, **to pay** in figures and words, and three
signature boxes: *Prepared by*, *Checked & paid by (second person)* and *Received by (group leader) — sign or thumb*.

## Rights

| Permission | Who has it | Allows |
|---|---|---|
| `wages.daily.manage` | Owner, Super Admin, Accountant, Supervisor, HR Manager | Groups, work categories, entering wage sheets, printing |
| `wages.daily.pay` | Owner, Super Admin, Accountant | Booking and paying wage sheets |

Migration `2026_10_31_000100_create_daily_wages_tables`; `php artisan access:sync` grants the permissions. The demo
data adds three work categories, two labour groups and three wage sheets (paid, owed, draft).
