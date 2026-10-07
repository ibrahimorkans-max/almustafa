# Phase 3 — Summary: Procurement & sauda, gate, weighbridge, lab, gate agent with simulators

## Built

| Area | What exists |
|---|---|
| Requirements | Paddy requirement (variety, quantity, target rate, moisture spec, delivery window) → broadcast to brokers / farmers / vendors (who and when recorded) → close |
| Quotes | Offer + counter-offers kept as append-only revisions; only the other side can accept; acceptance creates the sauda automatically |
| Saudas | Numbered per entity / series / Indian financial year (`KNL/P/2026-27/0001`). Lifecycle draft → pending approval → confirmed → in delivery → completed / cancelled / expired, every step an append-only event with actor and time. Approval is maker-checker; approved terms are frozen. Deliveries come from weighment slips; completion within tolerance, over-delivery recorded and flagged; nightly expiry with remaining quantity. Brokers see only their own deals |
| Gate | Arrival (blocked / unknown vehicle and closed-sauda flags), full inbound flow, outbound flow modelled; strict order on screens, lenient + anomaly marking for offline devices; cancel with reason |
| Weighbridge | Weighments from the indicator only, with stable-window evidence, photo hash, scale; flags for unverified stability, no photo, invalid certificate (or block), tare deviation; re-weighs supersede; Super Admin manual override via approval; scales + stamping / calibration certificates with daily expiry reminders (in-app notifications) |
| Lab | Tests (moisture, foreign matter, broken, damaged, immature, chalky, grade) by technicians; accept / reject by `lab.decide` before unloading; reject limits need a note; deduction rules (excess weight %, rate cut per point, bag weight) frozen into the decision |
| Slip | PDF from the frozen slip snapshot (gross / tare / net / deductions / payable, manual weights marked) with HMAC verification code; payable weight booked on the sauda |
| Sync | New append-only event types (`gate_entry.arrived`, `weighment.captured`, `lab_test.recorded`, `gate_entry.lab_decided`, `gate_entry.event`); new feeds `gate_entry`, `sauda` (rate hidden from gate staff); `retry` outcome; content-addressed photo upload (`POST /files`) |
| Agent | Python package `agent/`: indicator (RS-232 / TCP / simulator), stability detector, camera (HTTP / simulator), Fernet-encrypted SQLite outbox, signed HTTPS client, sync worker, CLI. Registration via Devices → "Register gate agent" (secret shown once) |
| Admin | Procurement (requirements, quotes, saudas), Gate & weighbridge (vehicles at gate with live refresh and every step as an action, weighbridges + certificates, quality deductions), agent registration |

## Tested
* **PostgreSQL:** 266 passed, 1 skipped (1687 assertions). **MariaDB:** 257 passed, 10 skipped.
* **Agent (Python):** 17 tests — shared signing vector with PHP, frame parsing, stability, encryption at rest, wrong key,
  ordering, wipe, offline capture with photo, unstable platform, signed sync, retry kept, offline loses nothing, token
  refresh, remote wipe, HTTPS enforcement.
* **PWA engine:** 13 tests (unchanged, still green).
* Procurement (12), gate flow (14), gate sync + agent signatures (11), phase 3 screens per role + UI flows (10),
  deductions + financial year (5), signature vector (1).
* **End-to-end against the running server:** the real agent (simulated indicator + camera) registered a truck, captured a
  stable gross weight with photo, synced over signed requests; the server created `KNL/G/2026-27/0001` (status
  gross_weighed, 24,560 kg, flag `unknown_vehicle`, photo stored and hash-verified, device `agent`).

## Fixed along the way
* `client_seq` is now unique-per-device instead of strictly increasing, so `retry` can work (ADR-0006).
* API requests without `Accept: application/json` got a 500 (login-route redirect); the API now always answers JSON.
* File uploads from the agent are raw bodies — PHP discards multipart bodies, which made them unsignable.

## Known gaps carried forward
* SMS / WhatsApp delivery for requirement broadcasts (needs a provider account).
* Sauda amendments (today: cancel + new deal); sales saudas and dispatch (phase 6).
* ANPR, boom barrier, display board, exit check (phase 6, same driver pattern).
* Public slip-verification page (phase 8); hardware-backed key storage on the gate PC (phase 9).

## Next: Phase 4 — Inventory, stock ledger, production and by-products
Append-only stock ledger per godown and item (paddy in from accepted slips, rice / husk / bran / broken out of
production), requisitions, batches and hourly production logs, input-to-output yield and standard outturn per variety
with drift alerts, end-of-day stock adjustment via maker-checker, shrinkage detection.
