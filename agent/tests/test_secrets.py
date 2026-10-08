import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from ricemill_agent import secrets
from ricemill_agent.config import AgentConfig

CONFIG = """
[server]
url = "https://erp.example.test"
[auth]
username = "wb.agent"
password = "{password}"
device_uid = "agent-0001"
signing_secret = "{signing}"
[store]
key = "{store}"
"""


def write_config(**values: str) -> Path:
    fd, name = tempfile.mkstemp(suffix=".toml")
    os.close(fd)
    Path(name).write_text(CONFIG.format(**values), encoding="utf-8")
    return Path(name)


class PlainSecretsTest(unittest.TestCase):
    def test_plain_values_pass_through(self):
        self.assertEqual(secrets.reveal("hunter2-plain"), "hunter2-plain")

    def test_environment_still_wins(self):
        path = write_config(password="from-file", signing="s", store="k")
        with mock.patch.dict(os.environ, {"RICEMILL_AGENT_PASSWORD": "from-env"}):
            self.assertEqual(AgentConfig.load(path).password, "from-env")
        path.unlink()

    @unittest.skipIf(sys.platform == "win32", "non-Windows behaviour")
    def test_protected_value_off_windows_is_a_clear_error(self):
        with self.assertRaisesRegex(secrets.SecretError, "only be read on the Windows PC"):
            secrets.reveal("dpapi:AAAA")
        with self.assertRaisesRegex(secrets.SecretError, "only available on Windows"):
            secrets.protect("x")


@unittest.skipUnless(sys.platform == "win32", "DPAPI is Windows-only")
class DpapiTest(unittest.TestCase):
    def test_round_trip_in_both_scopes(self):
        for scope in ("machine", "user"):
            protected = secrets.protect("signing-secret-ünïcode", scope)
            self.assertTrue(protected.startswith("dpapi:"))
            self.assertNotIn("signing-secret", protected)
            self.assertEqual(secrets.reveal(protected), "signing-secret-ünïcode")

    def test_each_protection_differs(self):
        self.assertNotEqual(secrets.protect("same"), secrets.protect("same"))

    def test_changed_or_garbage_values_are_refused(self):
        protected = secrets.protect("abc")
        raw = bytearray(__import__("base64").b64decode(protected[6:]))
        raw[-5] ^= 1
        changed = "dpapi:" + __import__("base64").b64encode(bytes(raw)).decode()
        with self.assertRaisesRegex(secrets.SecretError, "cannot be decrypted"):
            secrets.reveal(changed)
        with self.assertRaisesRegex(secrets.SecretError, "not base64"):
            secrets.reveal("dpapi:%%%")

    def test_config_decrypts_protected_values(self):
        path = write_config(password=secrets.protect("pw-123"), signing=secrets.protect("sig-456"), store=secrets.protect("key-789"))
        with mock.patch.dict(os.environ, {}, clear=False):
            for var in ("RICEMILL_AGENT_PASSWORD", "RICEMILL_AGENT_SIGNING_SECRET", "RICEMILL_AGENT_STORE_KEY"):
                os.environ.pop(var, None)
            cfg = AgentConfig.load(path)
        self.assertEqual((cfg.password, cfg.signing_secret, cfg.store_key), ("pw-123", "sig-456", b"key-789"))
        path.unlink()


if __name__ == "__main__":
    unittest.main()
