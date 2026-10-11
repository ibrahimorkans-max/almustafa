"""Agent tests (stdlib unittest): python -m unittest discover -s tests"""

from __future__ import annotations

import hashlib
import json
import sqlite3
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from ricemill_agent.api import ApiClient, ApiError, Credentials, OfflineError
from ricemill_agent.drivers.camera import SimulatedCamera
from ricemill_agent.drivers.indicator import FrameParser, SimulatedIndicator
from ricemill_agent.gate import GateAgent, NoStableWeight
from ricemill_agent.queue import LocalStore
from ricemill_agent.signing import canonical, sign
from ricemill_agent.stability import StableWeightDetector
from ricemill_agent.sync import SyncWorker


def temp_store() -> LocalStore:
    directory = tempfile.mkdtemp()
    return LocalStore(Path(directory) / "store.sqlite3", LocalStore.new_key())


class SigningTest(unittest.TestCase):
    def test_matches_the_server_test_vector(self) -> None:
        # Same vector as app/tests/Unit/AgentSignatureVectorTest.php
        headers = sign("test-secret-do-not-use", "post", "/api/v1/sync/push?x=1", b'{"events":[]}',
                       timestamp=1791360000, nonce="nonce-0000000000000001")
        self.assertEqual(headers["X-Signature"], "978e446264d4092aa50a22bc5b2687a349e67cba7a8ae6b49d65b09a46214081")

    def test_canonical_hashes_the_body(self) -> None:
        self.assertTrue(canonical("1", "n", "get", "/x", b"").endswith(hashlib.sha256(b"").hexdigest()))


class ParserTest(unittest.TestCase):
    def test_parses_common_frames(self) -> None:
        p = FrameParser()
        self.assertEqual(p.parse("+0025480kg"), Decimal("25480.000"))
        self.assertEqual(p.parse("=  25480 kg"), Decimal("25480.000"))
        self.assertEqual(p.parse("-0000020kg"), Decimal("-20.000"))
        self.assertEqual(p.parse("25.48 t"), Decimal("25480.000"))
        self.assertIsNone(p.parse("ERR"))

    def test_custom_pattern_needs_weight_group(self) -> None:
        with self.assertRaises(ValueError):
            FrameParser(r"(\d+)")


class StabilityTest(unittest.TestCase):
    def test_waits_for_a_stable_window(self) -> None:
        d = StableWeightDetector(Decimal("10"), min_samples=4)
        for i, w in enumerate(["25000", "25040", "25010", "25000", "25000", "25010"]):
            r = d.feed(Decimal(w), i * 250)
        self.assertIsNotNone(r)
        assert r is not None
        self.assertEqual(r.weight_kg, Decimal("25000"))
        self.assertEqual(r.evidence()["samples"], ["25010.000", "25000.000", "25000.000", "25010.000"])

    def test_ignores_an_empty_platform(self) -> None:
        d = StableWeightDetector(Decimal("10"), min_samples=3)
        self.assertIsNone([d.feed(Decimal("40"), i) for i in range(5)][-1])


class StoreTest(unittest.TestCase):
    def test_encrypts_everything_at_rest(self) -> None:
        store = temp_store()
        store.enqueue("gate_entry.arrived", "id-1", {"registration_no": "MNA211234"})
        store.put_file("ab" * 32, b"secret photo bytes")
        raw = Path(store._db.execute("PRAGMA database_list").fetchone()[2]).read_bytes()  # noqa: SLF001
        store._db.execute("PRAGMA wal_checkpoint(FULL)")  # noqa: SLF001
        raw += Path(store._db.execute("PRAGMA database_list").fetchone()[2]).read_bytes()  # noqa: SLF001
        self.assertNotIn(b"MNA211234", raw)
        self.assertNotIn(b"secret photo bytes", raw)

    def test_keeps_order_and_wipes(self) -> None:
        store = temp_store()
        for reg in ("A", "B", "C"):
            store.enqueue("x.y", None, {"r": reg})
        self.assertEqual([e.body["payload"]["r"] for e in store.pending()], ["A", "B", "C"])
        self.assertEqual([e.client_seq for e in store.pending()], [1, 2, 3])
        store.wipe()
        self.assertEqual(store.unsynced_count(), 0)

    def test_rejects_a_wrong_key(self) -> None:
        directory = tempfile.mkdtemp()
        LocalStore(Path(directory) / "s.db", LocalStore.new_key()).enqueue("x.y", None, {})
        other = LocalStore(Path(directory) / "s.db", LocalStore.new_key())
        with self.assertRaises(Exception):
            other.pending()


class GateAgentTest(unittest.TestCase):
    def test_captures_a_stable_weight_with_photo_offline(self) -> None:
        store = temp_store()
        agent = GateAgent(store, SimulatedIndicator(Decimal("25480"), seed=1), SimulatedCamera(), "scale-1", Decimal("10"))
        entry = agent.arrive("MNA211234", bag_count=400)
        capture = agent.capture(entry, "gross")

        self.assertEqual(capture.reading.weight_kg, Decimal("25480.000"))
        self.assertIsNotNone(capture.photo_sha256)
        events = [e.body for e in store.pending()]
        self.assertEqual([e["type"] for e in events], ["gate_entry.arrived", "weighment.captured"])
        weighment = events[1]["payload"]
        self.assertEqual(weighment["weight_kg"], "25480.000")
        self.assertEqual(weighment["gate_entry_id"], entry)
        self.assertGreaterEqual(len(weighment["readings"]["samples"]), 6)
        self.assertEqual(len(store.files_to_upload()), 1)

    def test_refuses_without_a_stable_reading(self) -> None:
        class Shaky:
            def samples(self):
                for i in range(50):
                    yield Decimal(25000 + (i % 2) * 200), i * 250

        agent = GateAgent(temp_store(), Shaky(), None, None, Decimal("10"), max_frames=50)
        with self.assertRaises(NoStableWeight):
            agent.capture("e", "gross")


class FakeServer:
    """Minimal server for the client + sync worker, recording what it received."""

    def __init__(self) -> None:
        self.requests: list[tuple[str, str, dict[str, str], bytes]] = []
        self.push_status = "applied"
        self.wipe = False
        self.offline = False
        self.expire_access_once = False

    def request(self, method: str, url: str, headers: dict[str, str], body: bytes) -> tuple[int, bytes]:
        if self.offline:
            raise OfflineError("no route to host")
        self.requests.append((method, url, headers, body))
        path = url.split("/api/v1", 1)[1]
        if path == "/auth/login":
            return 200, json.dumps({"status": "ok", "access_token": "rma_a", "refresh_token": "rmr_r"}).encode()
        if path == "/auth/refresh":
            return 200, json.dumps({"status": "ok", "access_token": "rma_b", "refresh_token": "rmr_s"}).encode()
        if self.expire_access_once:
            self.expire_access_once = False
            return 401, b'{"message":"Unauthenticated."}'
        if self.wipe and path != "/sync/wipe-ack":
            return 403, b'{"error":"wipe_required"}'
        if path == "/sync/bootstrap":
            return 200, b'{"max_offline_hours":72,"max_push_events":500}'
        if path == "/files":
            assert headers["Content-Type"] in ("image/png", "image/jpeg"), "raw body upload expected"
            return 201, json.dumps({"sha256": hashlib.sha256(body).hexdigest()}).encode()
        if path == "/sync/push":
            events = json.loads(body)["events"]
            return 200, json.dumps({"results": [{"event_id": e["event_id"], "status": self.push_status} for e in events]}).encode()
        if path.startswith("/sync/pull"):
            return 200, json.dumps({"changes": [{"type": "vehicle", "op": "upsert", "id": "v1", "data": {"registration_no": "MNA211234"}}],
                                    "next_cursor": 7, "has_more": False}).encode()
        if path == "/sync/wipe-ack":
            return 200, b'{"status":"wiped"}'
        return 404, b"{}"


def client(server: FakeServer) -> ApiClient:
    return ApiClient("http://127.0.0.1:8000", Credentials("wb.agent", "pw", "agent-pc-1", "s3cret"), transport=server)


class SyncTest(unittest.TestCase):
    def setUp(self) -> None:
        self.store = temp_store()
        self.server = FakeServer()
        agent = GateAgent(self.store, SimulatedIndicator(Decimal("24000"), seed=2), SimulatedCamera(), None, Decimal("10"))
        entry = agent.arrive("MNA211234")
        agent.capture(entry, "gross")

    def test_uploads_photos_then_events_and_pulls_with_signed_requests(self) -> None:
        report = SyncWorker(self.store, client(self.server)).run()

        self.assertEqual(report.state, "online")
        self.assertEqual((report.uploaded_files, report.pushed, report.pulled), (1, 2, 1))
        self.assertEqual(self.store.unsynced_count(), 0)
        self.assertEqual(self.store.get_meta("cursor"), "7")
        self.assertEqual(self.store.feed("vehicle")[0]["registration_no"], "MNA211234")
        for method, url, headers, body in self.server.requests:
            if "/auth/" in url:
                continue
            expected = sign("s3cret", method, url.split("127.0.0.1:8000", 1)[1], body,
                            timestamp=int(headers["X-Timestamp"]), nonce=headers["X-Nonce"])["X-Signature"]
            self.assertEqual(headers["X-Signature"], expected, url)
        nonces = [h["X-Nonce"] for _, u, h, _ in self.server.requests if "X-Nonce" in h]
        self.assertEqual(len(nonces), len(set(nonces)))

    def test_keeps_events_the_server_asks_to_retry(self) -> None:
        self.server.push_status = "retry"
        report = SyncWorker(self.store, client(self.server)).run()
        self.assertEqual(report.kept, 2)
        self.assertEqual(self.store.unsynced_count(), 2)

    def test_offline_loses_nothing(self) -> None:
        self.server.offline = True
        report = SyncWorker(self.store, client(self.server)).run()
        self.assertEqual(report.state, "offline")
        self.assertEqual(self.store.unsynced_count(), 2)

    def test_refreshes_an_expired_access_token(self) -> None:
        api = client(self.server)
        api.login()
        self.server.expire_access_once = True
        api.bootstrap()
        self.assertEqual(api.access, "rma_b")

    def test_obeys_remote_wipe(self) -> None:
        self.server.wipe = True
        report = SyncWorker(self.store, client(self.server)).run()
        self.assertEqual(report.state, "wiped")
        self.assertEqual(self.store.unsynced_count(), 0)
        self.assertTrue(any(u.endswith("/sync/wipe-ack") for _, u, _, _ in self.server.requests))

    def test_refuses_plain_http_to_remote_servers(self) -> None:
        with self.assertRaises(ValueError):
            ApiClient("http://erp.example.in", Credentials("u", "p", "d", "s"))


if __name__ == "__main__":
    unittest.main()


class LockdownTest(unittest.TestCase):
    """Server-side lockdown: the agent stops, says so, and loses nothing."""

    def setUp(self) -> None:
        self.store = temp_store()
        self.server = FakeServer()
        agent = GateAgent(self.store, SimulatedIndicator(Decimal("24000"), seed=2), SimulatedCamera(), None, Decimal("10"))
        agent.capture(agent.arrive("MNA211234"), "gross")
        real = self.server.request
        self.locked = True

        def locked_request(method: str, url: str, headers: dict[str, str], body: bytes) -> tuple[int, bytes]:
            if self.locked and not url.endswith("/auth/login"):
                self.server.requests.append((method, url, headers, body))
                return 401, b'{"error":"unauthenticated","reason":"system_locked","message":"System locked by administrator."}'
            return real(method, url, headers, body)

        self.server.request = locked_request  # type: ignore[method-assign]

    def test_stops_keeps_the_queue_and_resumes_after_unlock(self) -> None:
        api = client(self.server)
        report = SyncWorker(self.store, api).run()

        self.assertEqual(report.state, "locked")
        self.assertIn("System locked by administrator.", report.problems)
        self.assertEqual(self.store.unsynced_count(), 2)
        self.assertEqual(self.store.get_meta("stopped"), "system_locked")
        logins = [u for _, u, _, _ in self.server.requests if u.endswith("/auth/login")]
        self.assertLessEqual(len(logins), 1, "must not hammer the login endpoint while locked")

        self.locked = False
        report = SyncWorker(self.store, api).run()
        self.assertEqual(report.state, "online")
        self.assertEqual(self.store.unsynced_count(), 0)
        self.assertEqual(self.store.get_meta("stopped"), "")
