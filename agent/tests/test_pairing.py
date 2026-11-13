import json
import tempfile
import tomllib
import unittest
from pathlib import Path

from ricemill_agent.api import ApiError
from ricemill_agent.cli import login_command, pair_command
from ricemill_agent.pairing import normalize_code, normalize_server, set_values

EXAMPLE = Path(__file__).resolve().parents[1] / "agent.example.toml"

SETTINGS = {
    "status": "ok", "username": "wb.wb1", "password": "p" * 32, "device_uid": "agent-abcdefabcdefabcdefabcdef",
    "signing_secret": "s3cret", "scale_id": "6f1c2d1e-0000-4000-8000-000000000001", "scale_code": "WB1",
    "scale_name": "Main weighbridge", "resolution_kg": "10",
}


class FakeServer:
    """Answers /agent/pair and /auth/login like the ERP."""

    def __init__(self, password: str = SETTINGS["password"], code: str = "ABCDEF23") -> None:
        self.password, self.code = password, code
        self.requests: list[tuple[str, dict, dict]] = []

    def request(self, method, url, headers, body):
        data = json.loads(body) if body else {}
        self.requests.append((url, headers, data))
        if url.endswith("/api/v1/agent/pair"):
            if data.get("code") != self.code:
                return 422, json.dumps({"error": "pairing_code_invalid", "message": "wrong"}).encode()
            return 200, json.dumps(SETTINGS).encode()
        if url.endswith("/api/v1/auth/login"):
            if data.get("password") != self.password:
                return 401, json.dumps({"error": "invalid_credentials"}).encode()
            return 200, json.dumps({"status": "ok", "access_token": "a", "refresh_token": "r"}).encode()
        return 404, b"{}"


def plain(value: str) -> str:
    return value


class PairingTest(unittest.TestCase):
    def setUp(self) -> None:
        self.dir = tempfile.TemporaryDirectory()
        self.config = Path(self.dir.name) / "agent.toml"

    def tearDown(self) -> None:
        self.dir.cleanup()

    def test_pairing_writes_every_value_and_tests_the_sign_in(self) -> None:
        server = FakeServer()
        rc = pair_command(str(self.config), "millnexa.example.com/admin/", "abcd-ef23", transport=server, protect=plain)

        self.assertEqual(rc, 0)
        data = tomllib.loads(self.config.read_text(encoding="utf-8"))
        self.assertEqual(data["server"]["url"], "https://millnexa.example.com")
        self.assertEqual(data["auth"], {"username": "wb.wb1", "password": "p" * 32, "device_uid": SETTINGS["device_uid"], "signing_secret": "s3cret"})
        self.assertEqual(data["scale"]["id"], SETTINGS["scale_id"])
        self.assertTrue(data["store"]["key"])
        self.assertEqual(data["indicator"]["type"], "simulated")   # the rest of the example is kept
        self.assertEqual(server.requests[0][2], {"code": "ABCDEF23"})
        self.assertTrue(server.requests[1][0].endswith("/auth/login"))
        self.assertIn("X-Signature", server.requests[1][1])          # the sign-in is signed with the new secret

    def test_pairing_again_keeps_the_indicator_and_store_key(self) -> None:
        self.config.write_text(EXAMPLE.read_text(encoding="utf-8").replace('type = "simulated"                     # simulated | serial | tcp',
                                                                           'type = "serial"\nport = "COM3"', 1)
                               .replace('key = ""', 'key = "keep-me"', 1), encoding="utf-8")
        pair_command(str(self.config), "https://erp.mill.pk", "ABCDEF23", transport=FakeServer(), protect=plain)

        data = tomllib.loads(self.config.read_text(encoding="utf-8"))
        self.assertEqual((data["indicator"]["type"], data["indicator"]["port"]), ("serial", "COM3"))
        self.assertEqual(data["store"]["key"], "keep-me")

    def test_a_wrong_code_changes_nothing(self) -> None:
        with self.assertRaises(ApiError) as e:
            pair_command(str(self.config), "https://erp.mill.pk", "WRONG111", transport=FakeServer(), protect=plain)
        self.assertEqual(e.exception.body["error"], "pairing_code_invalid")
        self.assertFalse(self.config.exists())

    def test_login_saves_the_password_only_after_the_erp_accepts_it(self) -> None:
        pair_command(str(self.config), "https://erp.mill.pk", "ABCDEF23", transport=FakeServer(), protect=plain)
        typed = iter(["wrong-one", "right-one"])

        rc = login_command(str(self.config), None, transport=FakeServer(password="right-one"), ask=lambda prompt, default="": default,
                           ask_secret=lambda prompt: next(typed), protect=plain)

        self.assertEqual(rc, 0)
        self.assertEqual(tomllib.loads(self.config.read_text(encoding="utf-8"))["auth"]["password"], "right-one")

    def test_login_never_saves_a_refused_password(self) -> None:
        pair_command(str(self.config), "https://erp.mill.pk", "ABCDEF23", transport=FakeServer(), protect=plain)
        rc = login_command(str(self.config), None, transport=FakeServer(password="other"), ask=lambda prompt, default="": default,
                           ask_secret=lambda prompt: "wrong", protect=plain, tries=2)

        self.assertEqual(rc, 1)
        self.assertEqual(tomllib.loads(self.config.read_text(encoding="utf-8"))["auth"]["password"], "p" * 32)

    def test_helpers(self) -> None:
        self.assertEqual(normalize_server("ERP.Mill.pk/admin"), "https://erp.mill.pk")
        self.assertEqual(normalize_server("http://127.0.0.1:8000/"), "http://127.0.0.1:8000")
        self.assertEqual(normalize_code(" abcd - ef23 "), "ABCDEF23")
        text = '[auth]\nusername = "old"   # note\n\n[scale]\nid = ""\n'
        out = set_values(text, {"auth": {"username": "new", "password": 'a"b'}, "camera": {"type": "none"}})
        self.assertIn('username = "new"   # note', out)
        self.assertEqual(tomllib.loads(out)["auth"]["password"], 'a"b')
        self.assertEqual(tomllib.loads(out)["camera"]["type"], "none")


if __name__ == "__main__":
    unittest.main()
