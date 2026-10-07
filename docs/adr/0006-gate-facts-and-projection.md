# ADR-0006: Gate, weighbridge and lab as append-only facts with a projection

**Status:** accepted (phase 3)

## Context
Several devices record the same vehicle visit, often offline: the gate tablet (arrival), the weighbridge agent
(weights + photos), the lab tablet (tests, decision). Weights and lab results are evidence for payments and disputes.

## Decision
* **Facts are append-only**: `gate_events`, `weighments`, `lab_tests` (plus `sauda_events`, `quote_revisions`). DB
  triggers reject UPDATE/DELETE. A re-weigh or re-test is a new row that `supersedes` the earlier one.
* **The gate entry is a projection** (status, gross / tare / net, deductions, payable, flags), recomputed from the facts
  after every change by `GateService::project()`. Nothing in it is typed in.
* **Two ordering modes**: screens are strict (a step cannot be skipped); device sync is lenient — an out-of-order fact is
  kept and marked as an `anomaly`, so offline devices never lose data and supervisors see what happened.
* **Missing dependency → `retry`**: a fact about a gate entry the server has not received yet is not stored; the device
  sends it again later. `client_seq` therefore only has to be unique per device, not strictly increasing.
* **Weights only from the indicator**: stable-window evidence is re-checked server-side. The only typed weight is a
  Super Admin override with reason and second-person approval (`source = manual_override`, flagged on the slip).
* **Deductions are frozen** into the lab decision (the rules as they were then); the slip freezes the final numbers and
  carries an HMAC verification code; the payable weight is booked on the sauda.
* **Photos are content-addressed** (SHA-256 stored on the weighment; file re-hashable later).
* **Gate agents sign every request** (HMAC per device, nonce + timestamp) on top of device-bound tokens.

## Consequences
* Corrections are visible (superseded rows stay), never silent.
* The projection must be cheap — it reads only one vehicle's facts.
* Sales/dispatch (phase 6) reuses the outbound flow already modelled (`OUTBOUND_FLOW`).
