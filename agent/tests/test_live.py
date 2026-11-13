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
        stats = LiveWeightStreamer(samples, api, "scale-1", Decimal("10"), window=4, interval_s=1.0, clock=FakeClock()).run()

        self.assertEqual(20, stats.readings)
        self.assertEqual(5, stats.sent)                       # 20 readings × 0.25 s = 5 s
        self.assertTrue(all(c[1] == "/gate/live-weight" and c[2]["scale_id"] == "scale-1" for c in api.calls))
        weight, stable = stats.sent_values[-1]
        self.assertEqual(Decimal("24560"), Decimal(weight))     # settled at the end
        self.assertTrue(stable)
        self.assertFalse(stats.sent_values[0][1])                  # still driving on at the start

    def test_every_reading_reaches_the_erp_ten_a_second_in_sends_twice_a_second(self):
        # An indicator sending 20 frames a second for 3 s; the clock moves 50 ms per reading.
        frames = [(Decimal(1000 + 10 * i), 50 * i) for i in range(60)]
        clock = iter(i * 0.05 for i in range(1000))
        api = FakeApi()
        stats = LiveWeightStreamer(iter(frames), api, "s", Decimal("10"), clock=lambda: next(clock)).run()

        sent = [r for c in api.calls for r in c[2]["readings"]]
        self.assertEqual(stats.sent, len(api.calls))
        self.assertAlmostEqual(len(api.calls), 6, delta=1)              # every half second
        self.assertTrue(25 <= len(sent) <= 30, len(sent))               # 10 a second of the 20 read (the last half second unsent)
        last = api.calls[-1][2]
        self.assertEqual(last["kg"], last["readings"][-1]["kg"])        # the newest is also sent the old way
        self.assertEqual(0, last["readings"][-1]["age_ms"])
        self.assertEqual([100 * k for k in range(len(last["readings"]) - 1, -1, -1)], [r["age_ms"] for r in last["readings"]])

    def test_readings_read_during_a_slow_send_all_go_in_the_next_one(self):
        import time as t

        from ricemill_agent.live import in_background

        def indicator():                                   # 10 a second, timed when read
            for i in range(25):
                t.sleep(0.1)
                yield Decimal(1000 + 10 * i), int(t.monotonic() * 1000)

        class SlowApi(FakeApi):
            def call(self, method, path, json_body=None, **_):
                t.sleep(0.8)                               # the ERP takes 0.8 s to answer
                return super().call(method, path, json_body)

        api = SlowApi()
        stats = LiveWeightStreamer(in_background(indicator()), api, "s", Decimal("10")).run()

        counts = [len(c[2]["readings"]) for c in api.calls]
        self.assertGreaterEqual(max(counts), 3, counts)                         # not one reading a send
        self.assertGreaterEqual(sum(counts), 10, counts)                        # the last ones come after the last send
        self.assertEqual(stats.sent, len(api.calls))

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
