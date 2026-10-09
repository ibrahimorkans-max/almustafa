"""Gate automation: number-plate camera, boom barrier and display board.

* Every plate read is queued as a `gate_entry.plate_read` event (works offline).
* The barrier asks the server; it opens only on `open: true`. Offline or any error: it stays
  closed and the display tells the driver to wait (fail closed).
* A guard can open it by hand with a reason; that is queued as `barrier.manual_open` and the
  server flags the vehicle.
"""

from __future__ import annotations

import hashlib
import uuid
from dataclasses import dataclass
from decimal import Decimal

from .api import ApiClient, ApiError, OfflineError, stop_reason
from .drivers.anpr import PlateCamera
from .drivers.barrier import Barrier
from .drivers.display import Display
from .queue import LocalStore


@dataclass(frozen=True)
class BarrierResult:
    opened: bool
    reason: str
    plate: str | None
    gate_entry_id: str | None = None


class GateController:
    # Board messages fit 16 characters.
    STOP_MESSAGES = {
        "no_gate_pass": "STOP-NO GATEPASS",
        "no_slip": "STOP-NO SLIP",
        "blocked_vehicle": "STOP-BLOCKED",
        "no_matching_vehicle": "STOP-NOT ENTERED",
        "already_inside": "STOP-INSIDE",
        "dispatch_order_not_open": "STOP-ORDER HELD",
        "system_locked": "SYSTEM LOCKED",
        "access_disabled": "STOP-CALL ADMIN",
        "device_revoked": "STOP-CALL ADMIN",
        "maintenance": "MAINTENANCE",
    }

    def __init__(self, store: LocalStore, api: ApiClient | None, camera: PlateCamera | None, barrier: Barrier, display: Display | None = None) -> None:
        self.store = store
        self.api = api
        self.camera = camera
        self.barrier = barrier
        self.display = display

    def read_plate(self, gate_entry_id: str | None = None) -> str | None:
        """Reads the plate and queues the fact; returns the plate text (None if not recognised)."""
        if self.camera is None:
            return None
        try:
            read = self.camera.read()
        except Exception:  # noqa: BLE001 - a camera failure must not stop the gate; the guard types the number
            read = None
        if read is None:
            return None
        payload: dict = {"plate": read.plate, "confidence_bp": read.confidence_bp}
        if read.image:
            sha = hashlib.sha256(read.image).hexdigest()
            self.store.put_file(sha, read.image)
            payload["photo_sha256"] = sha
        if gate_entry_id:
            payload["gate_entry_id"] = gate_entry_id
        self.store.enqueue("gate_entry.plate_read", str(uuid.uuid4()), payload)
        return read.plate

    def request_passage(self, side: str, gate_entry_id: str | None = None) -> BarrierResult:
        """Plate read + server decision; opens the barrier only on an explicit yes."""
        plate = self.read_plate(gate_entry_id)
        if self.display and plate:
            self.display.show(plate, "PLEASE WAIT")

        decision: dict | None = None
        if self.api is not None:
            body: dict = {"side": side}
            if gate_entry_id:
                body["gate_entry_id"] = gate_entry_id
            if plate:
                body["plate"] = plate
            try:
                decision = self.api.call("POST", "/gate/barrier", body)
                self.store.set_meta("stopped", "")
            except ApiError as e:
                reason = stop_reason(e)
                if reason is not None:
                    # The barrier stays closed and the board says why; the plate read is still queued.
                    self.store.set_meta("stopped", reason)
                    self._show(plate, self.STOP_MESSAGES[reason])
                    return BarrierResult(False, reason, plate, gate_entry_id)
                decision = None
            except (OfflineError, OSError, ValueError):
                decision = None

        if decision is None:
            self._show(plate, "STOP-CALL GUARD")
            return BarrierResult(False, "server_unreachable", plate, gate_entry_id)
        if not decision.get("open"):
            reason = str(decision.get("reason", "refused"))
            self._show(plate, self.STOP_MESSAGES.get(reason, "STOP"))
            return BarrierResult(False, reason, plate, decision.get("gate_entry_id"))

        try:
            self.barrier.open()
        except OSError:
            self._show(plate, "BARRIER FAULT")
            return BarrierResult(False, "barrier_fault", plate, decision.get("gate_entry_id"))
        self._show(plate, "PROCEED")
        return BarrierResult(True, str(decision.get("reason", "")), plate, decision.get("gate_entry_id"))

    def manual_open(self, side: str, reason: str, gate_entry_id: str | None = None) -> None:
        """Guard override. Needs a reason; recorded and synced like any other fact."""
        if len(reason.strip()) < 5:
            raise ValueError("A manual barrier open needs a reason (at least 5 characters).")
        self.barrier.open()
        payload: dict = {"side": side, "reason": reason.strip()}
        if gate_entry_id:
            payload["gate_entry_id"] = gate_entry_id
        self.store.enqueue("barrier.manual_open", str(uuid.uuid4()), payload)
        self._show(None, "MANUAL OPEN")

    def show_weight(self, registration_no: str, weight_kg: Decimal) -> None:
        if self.display:
            self.display.show(registration_no, f"{weight_kg:.0f} KG")

    def _show(self, plate: str | None, message: str) -> None:
        if self.display:
            self.display.show(plate or "", message)
