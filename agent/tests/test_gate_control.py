"""Gate automation tests: plate reads, barrier fail-closed, manual override, display."""

from __future__ import annotations

import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from ricemill_agent.api import ApiClient, Credentials, OfflineError
from ricemill_agent.drivers.anpr import SimulatedAnprCamera
from ricemill_agent.drivers.barrier import SimulatedBarrier
from ricemill_agent.drivers.display import SimulatedDisplay
from ricemill_agent.gate_control import GateController
from ricemill_agent.queue import LocalStore


class BarrierServer:
    """Answers /gate/barrier with a configurable decision; records the requests."""

    def __init__(self, decision: dict | None = None, offline: bool = False, status: int = 200) -> None:
        self.decision = decision or {"open": True, "reason": "arrival_registered", "gate_entry_id": "e1"}
        self.offline = offline
        self.status = status
        self.barrier_requests: list[dict] = []

    def request(self, method: str, url: str, headers: dict[str, str], body: bytes) -> tuple[int, bytes]:
        if self.offline:
            raise OfflineError("no route to host")
        path = url.split("/api/v1", 1)[1]
        if path == "/auth/login":
            return 200, json.dumps({"status": "ok", "access_token": "rma_a", "refresh_token": "rmr_r"}).encode()
        if path == "/gate/barrier":
            assert "X-Signature" in headers, "barrier requests must be signed"
            self.barrier_requests.append(json.loads(body))
            return self.status, json.dumps(self.decision).encode()
        return 404, b"{}"


def setup(server: BarrierServer | None, plates: list[str] | None = None, barrier_fail: bool = False):
    store = LocalStore(Path(tempfile.mkdtemp()) / "s.sqlite3", LocalStore.new_key())
    api = ApiClient("http://127.0.0.1:8000", Credentials("gate", "pw", "gate-pc-1", "s3cret"), transport=server) if server else None
    camera = SimulatedAnprCamera(plates or ["HR05AB1234"])
    barrier = SimulatedBarrier(fail=barrier_fail)
    display = SimulatedDisplay()
    return store, GateController(store, api, camera, barrier, display), barrier, display


class GateControlTest(unittest.TestCase):
    def test_opens_on_the_server_yes_and_queues_the_plate_read(self) -> None:
        server = BarrierServer()
        store, gate, barrier, display = setup(server)

        result = gate.request_passage("in")

        self.assertTrue(result.opened)
        self.assertEqual(barrier.commands, ["open"])
        self.assertEqual(server.barrier_requests, [{"side": "in", "plate": "HR05AB1234"}])
        self.assertEqual(display.current, ("HR05AB1234", "PROCEED"))
        events = [e.body for e in store.pending()]
        self.assertEqual(events[0]["type"], "gate_entry.plate_read")
        self.assertEqual(events[0]["payload"]["confidence_bp"], 9600)
        self.assertEqual(len(events[0]["payload"]["photo_sha256"]), 64)

    def test_stays_closed_when_the_server_says_no(self) -> None:
        server = BarrierServer({"open": False, "reason": "no_gate_pass", "gate_entry_id": "e1"})
        _, gate, barrier, display = setup(server)

        result = gate.request_passage("out")

        self.assertFalse(result.opened)
        self.assertEqual(result.reason, "no_gate_pass")
        self.assertEqual(barrier.commands, [])
        self.assertEqual(display.current, ("HR05AB1234", "STOP-NO GATEPASS"))

    def test_fails_closed_offline_or_on_errors_but_keeps_the_plate_read(self) -> None:
        for server in (BarrierServer(offline=True), BarrierServer(status=500, decision={"message": "boom"}), None):
            store, gate, barrier, display = setup(server)
            result = gate.request_passage("in")
            self.assertFalse(result.opened)
            self.assertEqual(result.reason, "server_unreachable")
            self.assertEqual(barrier.commands, [])
            self.assertEqual(display.current, ("HR05AB1234", "STOP-CALL GUARD"))
            self.assertEqual(store.unsynced_count(), 1)  # plate read queued for later

    def test_reports_a_dead_relay_instead_of_pretending_it_opened(self) -> None:
        _, gate, _, display = setup(BarrierServer(), barrier_fail=True)

        result = gate.request_passage("in")

        self.assertFalse(result.opened)
        self.assertEqual(result.reason, "barrier_fault")
        self.assertEqual(display.current, ("HR05AB1234", "BARRIER FAULT"))

    def test_manual_open_needs_a_reason_and_is_queued(self) -> None:
        store, gate, barrier, _ = setup(None)
        with self.assertRaises(ValueError):
            gate.manual_open("out", "ok")
        self.assertEqual(barrier.commands, [])

        gate.manual_open("out", "Server down, perishable load waiting", gate_entry_id="e9")

        self.assertEqual(barrier.commands, ["open"])
        body = store.pending()[0].body
        self.assertEqual(body["type"], "barrier.manual_open")
        self.assertEqual(body["payload"], {"side": "out", "reason": "Server down, perishable load waiting", "gate_entry_id": "e9"})

    def test_a_misread_plate_is_forwarded_as_read(self) -> None:
        camera = SimulatedAnprCamera([])
        camera.queue("HR05AB1234", misread=True)
        store = LocalStore(Path(tempfile.mkdtemp()) / "s.sqlite3", LocalStore.new_key())
        gate = GateController(store, None, camera, SimulatedBarrier(), SimulatedDisplay())

        plate = gate.read_plate("e1")

        self.assertEqual(plate, "HR05AB1238")  # the server compares and flags; the agent never "fixes" it
        self.assertEqual(store.pending()[0].body["payload"]["gate_entry_id"], "e1")

    def test_shows_the_weight(self) -> None:
        _, gate, _, display = setup(None)
        gate.show_weight("HR05AB1234", Decimal("25480.000"))
        self.assertEqual(display.current, ("HR05AB1234", "25480 KG"))


if __name__ == "__main__":
    unittest.main()
