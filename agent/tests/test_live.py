import unittest
from decimal import Decimal

from ricemill_agent.api import OfflineError
from ricemill_agent.drivers.indicator import SimulatedIndicator
from ricemill_agent.live import LiveWeightStreamer


class FakeApi:
    def __init__(self, offline_after: int | None = None) -> None:
        self.calls: list[tuple[str, str, dict]] = []
        self.offline_after = offline_after

    def call(self, method, path, json_body=None, **_):
        if self.offline_after is not None and len(self.calls) >= self.offline_after:
            raise OfflineError("network down")
        self.calls.append((method, path, json_body))
        return {"ok": True}


class FakeClock:
    """Each reading is 0.25 s after the last, as the simulated indicator reports."""

    def __init__(self) -> None:
        self.t = 0.0

    def __call__(self) -> float:
        self.t += 0.25
        return self.t


class LiveWeightTest(unittest.TestCase):
    def test_sends_about_once_a_second_and_marks_the_settled_weight_stable(self):
        api = FakeApi()
        samples = SimulatedIndicator(Decimal("24560"), Decimal("10"), settle_frames=8, hold_frames=12, seed=7).samples()
        stats = LiveWeightStreamer(samples, api, "scale-1", Decimal("10"), interval_s=1.0, clock=FakeClock()).run()

        self.assertEqual(20, stats.readings)
        self.assertEqual(5, stats.sent)                       # 20 readings × 0.25 s = 5 s
        self.assertTrue(all(c[1] == "/gate/live-weight" and c[2]["scale_id"] == "scale-1" for c in api.calls))
        weight, stable = stats.sent_values[-1]
        self.assertEqual(Decimal("24560"), Decimal(weight))     # settled at the end
        self.assertTrue(stable)
        self.assertFalse(stats.sent_values[0][1])                  # still driving on at the start

    def test_offline_readings_are_dropped_not_queued_and_streaming_goes_on(self):
        api = FakeApi(offline_after=2)
        samples = SimulatedIndicator(Decimal("9870"), Decimal("10"), settle_frames=4, hold_frames=12, seed=1).samples()
        stats = LiveWeightStreamer(samples, api, "scale-1", Decimal("10"), interval_s=1.0, clock=FakeClock()).run()

        self.assertEqual(2, stats.sent)
        self.assertGreater(stats.dropped, 0)
        self.assertIn("network down", stats.last_error)

    def test_stops_after_the_asked_number_of_sends(self):
        api = FakeApi()
        samples = SimulatedIndicator(Decimal("9870"), Decimal("10"), hold_frames=40, seed=2).samples()
        stats = LiveWeightStreamer(samples, api, "s", Decimal("10"), clock=FakeClock()).run(max_sends=3)
        self.assertEqual(3, stats.sent)


if __name__ == "__main__":
    unittest.main()
