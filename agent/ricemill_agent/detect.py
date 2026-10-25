"""Finding how a weighing indicator talks, without the manual.

`ricemill-agent detect` listens on each COM port (or the one given) at each common speed for a few
seconds, cuts what arrives into frames and tries each known format. The combination that gives the
most readable, sensible weights wins, and the agent prints (or writes) the `[indicator]` settings.
Works for the PF-7E, XK3190, A12/A9, and most indicators with continuous RS-232 output.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from decimal import Decimal
from typing import Callable

from .drivers.indicator import FORMATS, make_parser, split_frames

BAUD_RATES = (9600, 4800, 2400, 1200, 19200, 38400, 115200)


@dataclass
class Candidate:
    port: str
    baudrate: int
    fmt: str
    frames: int = 0
    weights: list[Decimal] = field(default_factory=list)
    printable: float = 0.0
    bonus: float = 0.0

    @property
    def score(self) -> float:
        if len(self.weights) < 3:
            return 0.0
        plausible = [w for w in self.weights if Decimal("-1000") <= w <= Decimal("200000")]
        share = len(plausible) / len(self.weights)
        spread = (max(plausible) - min(plausible)) if plausible else Decimal(0)
        steady = 1.0 if spread <= Decimal("50000") else 0.5
        return len(plausible) * share * self.printable * steady + self.bonus

    def config(self) -> str:
        return "\n".join([
            "[indicator]",
            'type = "serial"',
            f'port = "{self.port}"',
            f"baudrate = {self.baudrate}",
            f'format = "{self.fmt}"',
        ])

    def describe(self) -> str:
        sample = ", ".join(format(w.normalize(), "f") for w in self.weights[-5:])
        return f"{self.port} at {self.baudrate} baud, format {self.fmt}: {len(self.weights)} readings (last: {sample} kg)"


def analyse(port: str, baudrate: int, data: bytes) -> list[Candidate]:
    """Every format tried on the same bytes."""
    printable = (sum(1 for b in data if 32 <= b < 127 or b in (10, 13, 2, 3)) / len(data)) if data else 0.0
    out = []
    for fmt in FORMATS:
        parser = make_parser(fmt)
        frames, _ = split_frames(data, reversed_format=fmt == "reversed")
        weights = [w for w in (parser.parse(f) for f in frames) if w is not None]
        # Ties go to the more specific format: status frames ("ST,GS,…") and bare reversed-digit frames ("=0.84520").
        specific = {"st_gs": r"^\s*(ST|US|OL),", "reversed": r"^=?\s*[0-9.]{5,9}-?\s*$"}.get(fmt)
        bonus = 0.5 if specific and frames and sum(1 for f in frames if re.match(specific, f)) >= len(frames) * 0.8 else 0.0
        out.append(Candidate(port, baudrate, fmt, len(frames), weights, printable, bonus))
    return out


def detect(ports: list[str], read: Callable[[str, int], bytes], bauds: tuple[int, ...] = BAUD_RATES,
           progress: Callable[[str], None] = lambda _m: None) -> list[Candidate]:
    """Best candidates first. `read(port, baud)` returns the bytes received in a few seconds."""
    found: list[Candidate] = []
    for port in ports:
        for baud in bauds:
            progress(f"Listening on {port} at {baud} baud…")
            try:
                data = read(port, baud)
            except Exception as e:  # noqa: BLE001 - a busy or missing port is reported, not fatal
                progress(f"  {port}: {e}")
                break
            if not data:
                progress("  nothing received")
                continue
            found.extend(c for c in analyse(port, baud, data) if c.score > 0)
            # A clearly working setting: no need to try slower speeds on this port.
            if any(c.score >= 10 for c in found if c.port == port and c.baudrate == baud):
                break
    return sorted(found, key=lambda c: c.score, reverse=True)


def serial_ports() -> list[tuple[str, str]]:  # pragma: no cover - needs the machine
    """(port, description) of every serial port Windows knows, e.g. ("COM3", "USB-SERIAL CH340")."""
    from serial.tools import list_ports

    return [(p.device, p.description or "") for p in list_ports.comports()]


def serial_reader(seconds: float = 3.0) -> Callable[[str, int], bytes]:  # pragma: no cover - needs hardware
    import time

    import serial

    def read(port: str, baud: int) -> bytes:
        with serial.Serial(port, baudrate=baud, bytesize=8, parity="N", stopbits=1, timeout=0.3) as s:
            data = b""
            end = time.monotonic() + seconds
            while time.monotonic() < end and len(data) < 4096:
                data += s.read(256)
            return data

    return read
