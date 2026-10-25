"""Live weight for the screens: the indicator's current value, about once a second.

Display only. A reading the server cannot take is dropped, never queued: a recorded weight still
comes only from `capture`, after the stable-weight window, with its evidence. "Stable" here means the
last few readings agree within one scale step (an empty platform reads stable at zero).
"""

from __future__ import annotations

import time
from collections import deque
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Callable, Iterator

from .api import ApiClient, ApiError, OfflineError
from .doctor import explain, kg_text

# Answers that will not change by trying again every second (wrong password, PC not allowed...):
# streaming stops so the login is not locked by repeated failures.
FATAL_STATUSES = {401, 403}


@dataclass
class LiveStats:
    readings: int = 0
    sent: int = 0
    dropped: int = 0
    last_error: str | None = None
    stopped: str | None = None
    sent_values: list[tuple[str, bool]] = field(default_factory=list)


class LiveWeightStreamer:
    def __init__(self, samples: Iterator[tuple[Decimal, int]], api: ApiClient, scale_id: str, resolution_kg: Decimal,
                 window: int = 4, interval_s: float = 1.0, clock: Callable[[], float] = time.monotonic,
                 report: Callable[[str], None] | None = None, report_every_s: float = 30.0) -> None:
        self.samples = samples
        self.api = api
        self.scale_id = scale_id
        self.resolution = resolution_kg
        self.interval = interval_s
        self.clock = clock
        self._recent: deque[Decimal] = deque(maxlen=max(2, window))
        self.report = report or (lambda _m: None)
        self.report_every = report_every_s

    def stable(self) -> bool:
        return len(self._recent) == self._recent.maxlen and max(self._recent) - min(self._recent) <= self.resolution

    def run(self, max_sends: int | None = None) -> LiveStats:
        stats = LiveStats()
        next_send = self.clock()
        next_report = next_send
        failing: str | None = None
        for weight, _at_ms in self.samples:
            stats.readings += 1
            self._recent.append(weight)
            now = self.clock()
            if now < next_send:
                continue
            next_send = now + self.interval
            stable = self.stable()
            try:
                self.api.call("POST", "/gate/live-weight", {"scale_id": self.scale_id, "kg": str(weight), "stable": stable})
                stats.sent += 1
                stats.sent_values.append((str(weight), stable))
                if failing is not None or stats.sent == 1 or now >= next_report:
                    self.report(f"{time.strftime('%H:%M:%S')}  sending to the ERP: {kg_text(weight)} kg" + (" (stable)" if stable else "")
                                + ("  - connection back" if failing is not None else ""))
                    next_report = now + self.report_every
                    failing = None
            except (OfflineError, ApiError) as e:
                stats.dropped += 1
                stats.last_error = str(e)
                problem = explain(e)
                if isinstance(e, ApiError) and e.status in FATAL_STATUSES:
                    self.report(str(problem) + "\nLive weight stopped; fix this and start it again.")
                    stats.stopped = problem.what
                    break
                if problem.what != failing:
                    self.report(f"{time.strftime('%H:%M:%S')}  NOT SENT - {problem.what}\n"
                                f"          {problem.fix}\n          (keeps trying every second)")
                    failing = problem.what
            if max_sends is not None and stats.sent + stats.dropped >= max_sends:
                break
        return stats
