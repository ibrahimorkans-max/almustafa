"""Stable-weight detection.

A weighbridge reading is accepted only when N consecutive samples stay within one scale step
(the indicator's resolution). The samples themselves are sent to the server as evidence; the
server re-checks them and flags weighments it cannot verify.

All values are Decimal kilograms - never floats.
"""

from __future__ import annotations

from collections import deque
from dataclasses import dataclass
from decimal import Decimal


@dataclass(frozen=True)
class StableReading:
    weight_kg: Decimal
    samples: tuple[Decimal, ...]
    window_ms: int

    def evidence(self) -> dict:
        """Payload for the server: samples as 3-decimal strings."""
        return {"samples": [f"{s:.3f}" for s in self.samples], "window_ms": self.window_ms}


class StableWeightDetector:
    def __init__(self, resolution_kg: Decimal, min_samples: int = 6, min_weight_kg: Decimal = Decimal("200")) -> None:
        if min_samples < 2:
            raise ValueError("min_samples must be at least 2")
        self.resolution = resolution_kg
        self.min_samples = min_samples
        self.min_weight = min_weight_kg
        self._window: deque[tuple[Decimal, int]] = deque(maxlen=min_samples)

    def reset(self) -> None:
        self._window.clear()

    def feed(self, weight_kg: Decimal, at_ms: int) -> StableReading | None:
        """Add one sample; returns a StableReading once the window is stable."""
        self._window.append((weight_kg, at_ms))
        if len(self._window) < self.min_samples:
            return None

        values = [w for w, _ in self._window]
        if max(values) - min(values) > self.resolution:
            return None
        if min(values) < self.min_weight:
            return None  # nothing (or only a person) on the platform

        # The recorded weight is the most frequent value in the window (indicators step in increments).
        weight = max(set(values), key=values.count)
        span = self._window[-1][1] - self._window[0][1]
        return StableReading(weight_kg=weight, samples=tuple(values), window_ms=span)
