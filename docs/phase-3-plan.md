# Phase 3 — Plan: Procurement & sauda, gate entry, weighbridge, lab, device simulators

## 1. Procurement
| Table | Purpose |
|---|---|
| `paddy_requirements` | What the mill wants to buy: variety, quantity (kg), target rate (paise/quintal), quality spec, delivery window; draft → open → closed. Broadcast to chosen brokers / farmers / traders (`requirement_recipients`, notified_at) |
| `quotes` + `quote_revisions` | Offers against a requirement (seller, broker, qty, rate, terms, validity); every offer / counter-offer is an append-only revision with who and when |
| `saudas` + `sauda_events` | The deal. Number per entity and financial year (`KNL/P/2026-27/0001`). Seller, broker, product, quantity, rate, brokerage, quality conditions, delivery period, payment days. Lifecycle **draft → pending approval → confirmed → in delivery → completed / cancelled / expired**, every transition an append-only event with actor and timestamp. Accepting a quote creates the sauda automatically; delivered quantity comes from net weighments; completion and expiry are automatic. Approval needs `deal.approve` by a different person |

## 2. Gate, weighbridge, lab (offline-capable, append-only)
| Table | Purpose |
|---|---|
| `scales`, `scale_certificates` | Weighbridges with calibration / Legal Metrology stamping certificates and expiry; reminders at 30 / 7 / 0 days; weighing on an expired scale is flagged |
| `gate_entries` + `gate_events` | Vehicle arrival (plate, driver, expected sauda, party, direction). Inbound paddy: **arrived → gross weighed → lab tested (accept / reject) → unloaded → tare weighed → slip issued → exited**. State changes are append-only events, so offline devices never overwrite each other |
| `weighments` | Append-only: gross / tare, kg, scale, stable-reading evidence, captured time, device, operator, camera photo SHA-256. Net = gross − tare. No typed weights: manual entry only as a **Super Admin override with reason + second-person approval**. Repeat-tare check flags a tare that differs from the vehicle's recent tares beyond a tolerance |
| `lab_tests` | Moisture, foreign matter, broken, damaged / discoloured, immature, chalky (all in basis points), grade, decision accept / reject / accept with deduction — before unloading. Corrections are new tests that supersede, never edits |
| `deduction_rules` | Per product: parameter, free limit, slab method (weight % per point above limit, or rate cut per quintal) → payable weight / rate |
| Weighment slip | PDF (dompdf) with gross / tare / net, deductions, lab result, photo hash, verification code |

Sync: `gate_entry.*`, `weighment.captured`, `lab_test.recorded` become **append-only** sync handlers; photos upload
through a content-addressed file endpoint into private storage.

## 3. Gate hardware agent (Python, `/agent`)
Driver interfaces + simulators for the weighbridge indicator (RS-232 / TCP continuous output, configurable frame
parser), stable-weight detection, camera (photo + hash), local encrypted queue, the same sync API, and **signed
requests** (HMAC per agent device, issued once by an admin). ANPR / boom / display drivers follow in phase 6 on the
same interfaces.

## 4. Assumptions
1. Indicator make is unknown: the parser is configurable (regex frame format), with a generic preset and a simulator.
2. Rates are paise per quintal (how paddy is traded); quantities and weights are kg internally.
3. Broadcast in this phase = recorded + visible to recipients through the API / broker access; SMS / WhatsApp
   delivery is an integration for later (needs a provider account).
4. Sales saudas arrive with phase 6; the sauda table is built for both directions now.
