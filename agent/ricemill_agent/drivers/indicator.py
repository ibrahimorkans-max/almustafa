"""Weighbridge indicator drivers (continuous output over RS-232 or TCP).

Indicators from different makers print different frames, e.g.

    "ST,GS,+0025480kg"      (stable, gross)
    "US,GS,+0025470kg"      (unstable)
    "=  25480 kg"

so the frame format is a configurable regular expression with named groups:
``weight`` (required), ``sign`` and ``unit`` (optional). The agent never trusts the
indicator's own "stable" flag alone - stability is decided by StableWeightDetector.
"""

from __future__ import annotations

import random
import re
import socket
import time
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation
from typing import Iterator, Protocol

DEFAULT_FRAME = r"(?P<sign>[+-])?\s*0*(?P<weight>\d+(?:\.\d+)?)\s*(?P<unit>kg|t)?"


class WeightIndicator(Protocol):
    def samples(self) -> Iterator[tuple[Decimal, int]]:
        """Yields (weight in kg, timestamp in ms) for every frame received."""
        ...


@dataclass
class FrameParser:
    pattern: str = DEFAULT_FRAME
    _regex: re.Pattern[str] = field(init=False, repr=False)

    def __post_init__(self) -> None:
        self._regex = re.compile(self.pattern)
        if "weight" not in self._regex.groupindex:
            raise ValueError("Frame pattern needs a named group 'weight'.")

    def parse(self, frame: str) -> Decimal | None:
        match = self._regex.search(frame.strip())
        if not match:
            return None
        try:
            value = Decimal(match.group("weight"))
        except InvalidOperation:
            return None
        groups = match.groupdict()
        if groups.get("sign") == "-":
            value = -value
        if (groups.get("unit") or "kg").lower() == "t":
            value = value * 1000
        return value.quantize(Decimal("0.001"))


class _LineIndicator:
    """Common loop for line-based indicators: read a line, parse it, yield it."""

    def __init__(self, parser: FrameParser) -> None:
        self.parser = parser

    def _lines(self) -> Iterator[str]:  # pragma: no cover - hardware
        raise NotImplementedError

    def samples(self) -> Iterator[tuple[Decimal, int]]:
        for line in self._lines():
            weight = self.parser.parse(line)
            if weight is not None:
                yield weight, int(time.monotonic() * 1000)


class SerialIndicator(_LineIndicator):  # pragma: no cover - needs hardware
    def __init__(self, port: str, baudrate: int, parser: FrameParser) -> None:
        super().__init__(parser)
        import serial  # optional dependency: pip install ricemill-agent[serial]

        self.port = serial.Serial(port, baudrate=baudrate, timeout=2)

    def _lines(self) -> Iterator[str]:
        while True:
            raw = self.port.readline()
            if raw:
                yield raw.decode("ascii", errors="ignore")


class TcpIndicator(_LineIndicator):  # pragma: no cover - needs hardware
    def __init__(self, host: str, port: int, parser: FrameParser) -> None:
        super().__init__(parser)
        self.address = (host, port)

    def _lines(self) -> Iterator[str]:
        with socket.create_connection(self.address, timeout=5) as sock:
            buffer = b""
            while True:
                chunk = sock.recv(256)
                if not chunk:
                    return
                buffer += chunk
                while b"\n" in buffer:
                    line, buffer = buffer.split(b"\n", 1)
                    yield line.decode("ascii", errors="ignore")


class SimulatedIndicator:
    """A truck driving onto the platform: weight rises, wobbles, settles, then holds.

    Produces frames in the indicator's own text format so the parser is exercised too.
    """

    def __init__(self, target_kg: Decimal, resolution_kg: Decimal = Decimal("10"), settle_frames: int = 8,
                 hold_frames: int = 10, seed: int | None = None, parser: FrameParser | None = None) -> None:
        self.target = target_kg
        self.resolution = resolution_kg
        self.settle_frames = settle_frames
        self.hold_frames = hold_frames
        self.random = random.Random(seed)
        self.parser = parser or FrameParser()
        self.frames_sent: list[str] = []

    def frames(self) -> Iterator[str]:
        steps = int(self.target / self.resolution)
        for i in range(1, self.settle_frames + 1):  # driving on / wobbling
            wobble = self.random.randint(-30, 30)
            value = self.resolution * max(0, steps * i // self.settle_frames + wobble)
            yield f"US,GS,+{int(value):07d}kg"
        for _ in range(self.hold_frames):  # stable
            yield f"ST,GS,+{int(self.target):07d}kg"

    def samples(self) -> Iterator[tuple[Decimal, int]]:
        at = 0
        for frame in self.frames():
            self.frames_sent.append(frame)
            weight = self.parser.parse(frame.split(",")[-1])
            at += 250
            if weight is not None:
                yield weight, at
