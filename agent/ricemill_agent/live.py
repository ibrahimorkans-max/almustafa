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


@dataclass
class LiveStats:
    readings: int = 0
    sent: int = 0
    dropped: int = 0
    last_error: str | None = None
    sent_values: list[tuple[str, bool]] = field(default_factory=list)


class LiveWeightStreamer:
    def __init__(self, samples: Iterator[tuple[Decimal, int]], api: ApiClient, scale_id: str, resolution_kg: Decimal,
                 window: int = 4, interval_s: float = 1.0, clock: Callable[[], float] = time.monotonic) -> None:
        self.samples = samples
        self.api = api
        self.scale_id = scale_id
        self.resolution = resolution_kg
        self.interval = interval_s
        self.clock = clock
        self._recent: deque[Decimal] = deque(maxlen=max(2, window))

    def stable(self) -> bool:
        return len(self._recent) == self._recent.maxlen and max(self._recent) - min(self._recent) <= self.resolution

    def run(self, max_sends: int | None = None) -> LiveStats:
        stats = LiveStats()
        next_send = self.clock()
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
            except (OfflineError, ApiError) as e:
                stats.dropped += 1
                stats.last_error = str(e)
            if max_sends is not None and stats.sent + stats.dropped >= max_sends:
                break
        return stats
