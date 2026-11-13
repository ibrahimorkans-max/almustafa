"""Live weight for the screens: every reading of the indicator (about 10 a second), sent twice a second.

Display only. A reading the server cannot take is dropped, never queued: a recorded weight still
comes only from `capture`, after the stable-weight window, with its evidence. "Stable" here means the
last few readings agree within one scale step (an empty platform reads stable at zero).
"""

from __future__ import annotations

import queue
import threading
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


class in_background:  # noqa: N801 - used like a function: in_background(indicator.samples())
    """The indicator read on its own thread, so each reading keeps the time it was read while a send waits for the
    ERP. `backlog()` tells the streamer readings are still waiting: it takes them all before the next send."""

    _DONE = object()

    def __init__(self, samples: Iterator[tuple[Decimal, int]], keep: int = 200) -> None:
        self._q: queue.Queue = queue.Queue(maxsize=keep)
        self._source = samples
        threading.Thread(target=self._pump, name="indicator", daemon=True).start()

    def _pump(self) -> None:
        try:
            for item in self._source:
                try:
                    self._q.put_nowait(item)
                except queue.Full:                 # the sender is stuck: drop the oldest, keep reading
                    try:
                        self._q.get_nowait()
                    except queue.Empty:
                        pass
                    self._q.put_nowait(item)
        finally:
            self._q.put(self._DONE)

    def backlog(self) -> int:
        return self._q.qsize()

    def __iter__(self) -> "in_background":
        return self

    def __next__(self) -> tuple[Decimal, int]:
        item = self._q.get()
        if item is self._DONE:
            raise StopIteration
        return item


@dataclass
class LiveStats:
    readings: int = 0
    sent: int = 0
    sent_readings: int = 0
    dropped: int = 0
    last_error: str | None = None
    stopped: str | None = None
    sent_values: list[tuple[str, bool]] = field(default_factory=list)


class LiveWeightStreamer:
    """Sends, every interval_s (half a second), every reading since the last send: up to per_second a second.

    "Stable" is judged over the last `window` readings (10: about one second at 10 a second).
    """

    MAX_BATCH = 40

    def __init__(self, samples: Iterator[tuple[Decimal, int]], api: ApiClient, scale_id: str, resolution_kg: Decimal,
                 window: int = 10, interval_s: float = 0.5, clock: Callable[[], float] = time.monotonic,
                 report: Callable[[str], None] | None = None, report_every_s: float = 5.0, per_second: int = 10) -> None:
        self.samples = samples
        self.api = api
        self.scale_id = scale_id
        self.resolution = resolution_kg
        self.interval = interval_s
        self.clock = clock
        self._recent: deque[Decimal] = deque(maxlen=max(2, window))
        self.report = report or (lambda _m: None)
        self.report_every = report_every_s
        self.min_gap_ms = 1000 // max(1, per_second) - 5     # a little slack for the indicator's own timing

    def stable(self) -> bool:
        return len(self._recent) == self._recent.maxlen and max(self._recent) - min(self._recent) <= self.resolution

    def run(self, max_sends: int | None = None) -> LiveStats:
        stats = LiveStats()
        next_send = self.clock()
        next_report = next_send
        failing: str | None = None
        batch: list[tuple[Decimal, bool, int]] = []          # (kg, stable, read at ms), oldest first
        backlog: Callable[[], int] = getattr(self.samples, "backlog", lambda: 0)
        due: float | None = None
        last_kept: int | None = None
        for weight, at_ms in self.samples:
            stats.readings += 1
            self._recent.append(weight)
            # Every reading the screens show, at most per_second of them (an indicator may send 20 a second).
            if last_kept is None or at_ms - last_kept >= self.min_gap_ms or at_ms < last_kept:
                batch.append((weight, self.stable(), at_ms))
                del batch[:-self.MAX_BATCH]
                last_kept = at_ms
            now = self.clock()
            if now < next_send:
                continue
            # Readings still waiting (read during the last send): take them first, but never for more than 2 s.
            due = now if due is None else due
            if backlog() > 0 and now < due + 4 * self.interval:
                continue
            due = None
            next_send = now + self.interval
            weight, stable, newest = batch[-1] if batch else (weight, self.stable(), at_ms)
            readings = [{"kg": str(kg), "stable": st, "age_ms": max(0, newest - at)} for kg, st, at in batch]
            try:
                self.api.call("POST", "/gate/live-weight", {"scale_id": self.scale_id, "kg": str(weight), "stable": stable,
                                                            "readings": readings})
                stats.sent += 1
                stats.sent_readings += len(readings)
                stats.sent_values.append((str(weight), stable))
                if failing is not None or stats.sent == 1 or now >= next_report:
                    self.report(f"{time.strftime('%H:%M:%S')}  sending to the ERP: {kg_text(weight)} kg" + (" (stable)" if stable else "")
                                + f"  [{len(readings)} readings in this send]"
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
                                f"          {problem.fix}\n          (keeps trying)")
                    failing = problem.what
            batch.clear()
            if max_sends is not None and stats.sent + stats.dropped >= max_sends:
                break
        return stats
