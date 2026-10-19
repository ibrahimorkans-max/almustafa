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


class ReversedDigitsParser:
    """Yaohua-style continuous output (XK3190 "tf=0", many PF-series and other Chinese indicators):
    each frame is "=" followed by the weight with its characters in REVERSE order, e.g. "=0.84520" is
    2548.0 kg and "=08452-" is -25480 kg (the sign, when sent, is last). No line end; "=" starts a frame."""

    pattern = "reversed"

    def parse(self, frame: str) -> Decimal | None:
        text = frame.strip().lstrip("=").strip()
        if not text or not re.fullmatch(r"[0-9.\-+ ]+", text):
            return None
        reversed_text = text[::-1].replace(" ", "")
        sign = -1 if "-" in reversed_text else 1
        digits = reversed_text.replace("-", "").replace("+", "")
        if digits.count(".") > 1 or not any(c.isdigit() for c in digits):
            return None
        try:
            return (Decimal(digits) * sign).quantize(Decimal("0.001"))
        except InvalidOperation:
            return None


# Named frame formats for `[indicator] format = "…"` (or a custom `frame_pattern`).
FORMATS: dict[str, str] = {
    # "ST,GS,+0025480kg", "=  25480 kg", "WN0025.480kg", "  25480": the first number with its sign and unit.
    "standard": DEFAULT_FRAME,
    # Status + gross/net: "ST,GS,+0025480kg" / "US,NT,-0000020kg"; frames from other lines are ignored.
    "st_gs": r"(?:ST|US|OL),(?:GS|NT|TR),\s*(?P<sign>[+-])?\s*0*(?P<weight>\d+(?:\.\d+)?)\s*(?P<unit>kg|t)?",
    # "=0.84520" reversed digits (see ReversedDigitsParser).
    "reversed": "",
}

# Bytes that end a frame: CR, LF, STX, ETX and, for the reversed format, "=" (which starts the next one).
FRAME_ENDS = b"\r\n\x02\x03"


def make_parser(fmt: str | None = None, pattern: str | None = None) -> FrameParser | ReversedDigitsParser:
    """The parser for a configured format name or custom regular expression."""
    if pattern:
        return FrameParser(pattern)
    name = (fmt or "standard").lower()
    if name in ("reversed", "yaohua", "tf0"):
        return ReversedDigitsParser()
    if name not in FORMATS:
        raise ValueError(f"Unknown indicator format {fmt!r}; use one of {', '.join(FORMATS)} or a frame_pattern.")
    return FrameParser(FORMATS[name])


def split_frames(buffer: bytes, reversed_format: bool = False) -> tuple[list[str], bytes]:
    """Cuts a byte stream into frames; returns the complete frames and the unfinished rest."""
    ends = FRAME_ENDS + (b"=" if reversed_format else b"")
    frames: list[str] = []
    start = 0
    for i, byte in enumerate(buffer):
        if byte in ends:
            piece = buffer[start:i]
            if piece.strip():
                frames.append(piece.decode("ascii", errors="ignore"))
            start = i + 1
    return frames, buffer[start:]


class _StreamIndicator:
    """Reads raw bytes, cuts them into frames, parses each into a weight."""

    def __init__(self, parser: FrameParser | ReversedDigitsParser) -> None:
        self.parser = parser
        self.reversed = isinstance(parser, ReversedDigitsParser)

    def _chunks(self) -> Iterator[bytes]:  # pragma: no cover - hardware
        raise NotImplementedError

    def frames(self) -> Iterator[str]:
        buffer = b""
        for chunk in self._chunks():
            buffer += chunk
            frames, buffer = split_frames(buffer, self.reversed)
            yield from frames
            if len(buffer) > 4096:  # garbage without frame ends (wrong speed): start again
                buffer = b""

    def samples(self) -> Iterator[tuple[Decimal, int]]:
        for frame in self.frames():
            weight = self.parser.parse(frame)
            if weight is not None:
                yield weight, int(time.monotonic() * 1000)


PARITIES = {"none": "N", "even": "E", "odd": "O", "n": "N", "e": "E", "o": "O"}


class SerialIndicator(_StreamIndicator):  # pragma: no cover - needs hardware
    """RS-232 (a COM port, or a USB-to-RS232 adapter). Most indicators: 9600 baud, 8 data bits, no parity, 1 stop bit."""

    def __init__(self, port: str, baudrate: int, parser: FrameParser | ReversedDigitsParser,
                 bytesize: int = 8, parity: str = "none", stopbits: int = 1) -> None:
        super().__init__(parser)
        import serial  # optional dependency: pip install ricemill-agent[serial]

        self.port = serial.Serial(port, baudrate=baudrate, bytesize=bytesize, parity=PARITIES.get(parity.lower(), "N"),
                                  stopbits=stopbits, timeout=1)

    def _chunks(self) -> Iterator[bytes]:
        while True:
            chunk = self.port.read(64)
            if chunk:
                yield chunk


class TcpIndicator(_StreamIndicator):  # pragma: no cover - needs hardware
    """An indicator with a network port, or an RS-232-to-Ethernet converter (e.g. USR-TCP232)."""

    def __init__(self, host: str, port: int, parser: FrameParser | ReversedDigitsParser) -> None:
        super().__init__(parser)
        self.address = (host, port)

    def _chunks(self) -> Iterator[bytes]:
        with socket.create_connection(self.address, timeout=5) as sock:
            while True:
                chunk = sock.recv(256)
                if not chunk:
                    return
                yield chunk


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
