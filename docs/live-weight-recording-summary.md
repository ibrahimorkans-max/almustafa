# Live weight recording, weight checks, connecting a weighbridge

| Feature | What it does |
|---|---|
| Record from the Live weight screen | Each weighbridge card lists the vehicles waiting for a weight (inbound: gross on arrival, tare after unloading; outbound: tare on arrival, gross after loading). With `weighment.capture`, the operator picks the vehicle and presses **Record gross / Record tare**. The server takes the gate agent's latest reading only when it is live, marked stable, and the last `ricemill.gate.min_stable_samples` readings agree within one scale step; those readings are stored as the evidence, so the usual stability check runs. The weighment is flagged `live_screen` (and `no_photo`) |
| Live readings kept | `LiveWeight` keeps the last 12 readings per weighbridge in the cache next to the current one; keyed by the weighbridge's own mill, so the Owner in "All firms" mode sees the readings too |
| Weight checks | **Gate & weighbridge → Weight checks** (`weighment.review`, held by the Owner and each mill's Super Admin): every weighment with how it was taken, who recorded it, flags and evidence; **OK** (optional note) or **Query** (note required). Checks are an append-only table (`weighment_checks`, RLS + triggers); the latest counts; nobody checks a weight they recorded; the menu shows the unchecked count; every check is audited (`weighment.checked`) |
| Connect a weighbridge | **Gate & weighbridge → Connect a weighbridge** (`master.manage`): connection state of each weighbridge (signal, last reading) and the steps; the full guide is [connect-weighbridge.md](connect-weighbridge.md) |

Migration: `2026_10_30_000100_create_weighment_checks`. Permission `weighment.review` is created by
`php artisan access:sync` and granted to the Owner and to every mill's Super Admin role.
