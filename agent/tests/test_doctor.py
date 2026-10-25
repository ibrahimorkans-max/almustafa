import tempfile
import tomllib
import unittest
from decimal import Decimal
from pathlib import Path
from types import SimpleNamespace

from ricemill_agent.api import ApiError, OfflineError
from ricemill_agent.doctor import explain, run_check
from ricemill_agent.drivers.indicator import SimulatedIndicator
from ricemill_agent.live import LiveWeightStreamer

GOOD = """[server]
url = "https://erp.mill.pk"

[auth]
username = "wb.agent"
password = "dpapi:x"
device_uid = "agent-abc123"
signing_secret = "dpapi:y"

[scale]
id = "6f1c2d1e-0000-4000-8000-000000000001"
"""


class FakeApi:
    def __init__(self, login_error: Exception | None = None, call_error: Exception | None = None) -> None:
        self.login_error, self.call_error = login_error, call_error
        self.calls: list = []

    def login(self):
        if self.login_error:
            raise self.login_error
        return {"status": "ok"}

    def call(self, method, path, json_body=None, **_):
        if self.call_error:
            raise self.call_error
        self.calls.append((method, path, json_body))
        return {"ok": True}


def cfg(_path):
    return SimpleNamespace(server_url="https://erp.mill.pk", username="wb.agent", scale_id="6f1c2d1e-0000-4000-8000-000000000001",
                           resolution_kg=Decimal("5"))


def samples(_cfg):
    return SimulatedIndicator(Decimal("85"), Decimal("5"), settle_frames=2, hold_frames=10, seed=1).samples()


class ExplainTest(unittest.TestCase):
    def test_every_problem_becomes_a_sentence_with_a_fix(self):
        cases = [
            (OfflineError("The read operation timed out"), "did not answer within 15 seconds"),
            (OfflineError("[Errno 11001] getaddrinfo failed"), "cannot be found"),
            (ApiError(401, {"error": "invalid_credentials"}), "refused the PC's username or password"),
            (ApiError(403, {"error": "device_pending_approval"}), "waiting for approval"),
            (ApiError(403, {"error": "device_not_allowed"}), "does not know this PC"),
            (ApiError(403, {"error": "password_change_required"}), "temporary"),
            (ApiError(422, {"message": "Unknown weighbridge."}), "Unknown weighbridge"),
            (ValueError("Missing secret: set RICEMILL_AGENT_PASSWORD or the config value."), "secret is missing"),
            (KeyboardInterrupt(), "Ctrl+C"),
        ]
        for error, words in cases:
            problem = explain(error, "https://erp.mill.pk")
            self.assertIn(words, problem.what, type(error).__name__)
            self.assertTrue(problem.fix)

    def test_a_broken_settings_line_is_named(self):
        try:
            tomllib.loads("[indicator]\ntype = \"serial\"\n[+-])?")
        except tomllib.TOMLDecodeError as e:
            self.assertIn("line 3", explain(e).what)
            self.assertIn("notepad agent.toml", explain(e).fix)


class CheckTest(unittest.TestCase):
    def run_check(self, text: str, api: FakeApi, reach=lambda url: 0.4):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / "agent.toml"
            path.write_text(text, encoding="utf-8")
            lines: list[str] = []
            steps = run_check(str(path), load_config=cfg, make_api=lambda c: api, make_samples=samples, reach=reach,
                              read_seconds=0.2, out=lines.append)
            return steps, "\n".join(lines)

    def test_all_steps_pass_and_one_live_reading_is_sent(self):
        api = FakeApi()
        steps, text = self.run_check(GOOD, api)
        self.assertEqual([s.name for s in steps], ["Settings file", "Values from the ERP", "Indicator", "ERP server", "Sign-in", "Live weight"])
        self.assertTrue(all(s.ok for s in steps))
        self.assertEqual(api.calls[0][1], "/gate/live-weight")
        self.assertIn("All good", text)

    def test_stops_at_values_not_copied_from_the_erp(self):
        steps, text = self.run_check(GOOD.replace('id = "6f1c2d1e-0000-4000-8000-000000000001"', 'id = ""'), FakeApi())
        self.assertFalse(steps[-1].ok)
        self.assertIn("[scale] id", text)
        self.assertIn("Details to copy", text)

    def test_stops_at_a_server_that_does_not_answer(self):
        def slow(_url):
            raise OfflineError("timed out")

        steps, text = self.run_check(GOOD, FakeApi(), reach=slow)
        self.assertEqual(steps[-1].name, "ERP server")
        self.assertIn("did not answer within 15 seconds", text)

    def test_stops_at_a_refused_sign_in(self):
        steps, text = self.run_check(GOOD, FakeApi(login_error=ApiError(401, {"error": "invalid_credentials"})))
        self.assertEqual(steps[-1].name, "Sign-in")
        self.assertIn("WHAT TO DO", text)


class LiveReportTest(unittest.TestCase):
    def test_a_refused_sign_in_stops_streaming_instead_of_retrying_every_second(self):
        api = FakeApi(call_error=ApiError(401, {"error": "invalid_credentials"}))
        lines: list[str] = []
        stats = LiveWeightStreamer(samples(None), api, "s", Decimal("5"), report=lines.append).run()
        self.assertEqual(stats.dropped, 1)
        self.assertIn("username or password", stats.stopped or "")
        self.assertIn("Live weight stopped", lines[-1])

    def test_says_when_sending_works_and_when_the_network_drops(self):
        class Flaky(FakeApi):
            n = 0

            def call(self, *a, **k):
                Flaky.n += 1
                if Flaky.n in (2, 3):
                    raise OfflineError("timed out")
                return super().call(*a, **k)

        lines: list[str] = []
        clock = iter(i * 0.5 for i in range(1000))
        LiveWeightStreamer(samples(None), Flaky(), "s", Decimal("5"), interval_s=1.0, clock=lambda: next(clock),
                           report=lines.append).run(max_sends=5)
        joined = "\n".join(lines)
        self.assertIn("sending to the ERP", lines[0])
        self.assertEqual(joined.count("NOT SENT"), 1)   # said once, not every second
        self.assertIn("connection back", joined)


if __name__ == "__main__":
    unittest.main()
