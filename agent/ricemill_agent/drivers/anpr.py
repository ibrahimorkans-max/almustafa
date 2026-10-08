"""Number-plate (ANPR) cameras. Most gate ANPR cameras recognise the plate themselves and expose
the latest result over HTTP; the agent only forwards the result, the confidence and the photo hash.
The server compares it with the typed registration and flags a mismatch - nothing is corrected."""

from __future__ import annotations

import json
import urllib.request
from dataclasses import dataclass
from typing import Protocol


@dataclass(frozen=True)
class PlateRead:
    plate: str
    confidence_bp: int  # 0-10000
    image: bytes | None = None


class PlateCamera(Protocol):
    def read(self) -> PlateRead | None:
        """Latest plate in front of the camera, or None if nothing was recognised."""
        ...


class HttpAnprCamera:  # pragma: no cover - needs a camera
    """Camera returning JSON such as {"plate": "HR05AB1234", "confidence": 0.97} at `result_url`,
    and a JPEG at `snapshot_url`."""

    def __init__(self, result_url: str, snapshot_url: str | None = None, timeout: float = 5.0) -> None:
        self.result_url = result_url
        self.snapshot_url = snapshot_url
        self.timeout = timeout

    def read(self) -> PlateRead | None:
        with urllib.request.urlopen(self.result_url, timeout=self.timeout) as response:  # noqa: S310 - configured URL
            data = json.loads(response.read() or b"{}")
        plate = str(data.get("plate") or "").strip()
        if not plate:
            return None
        image = None
        if self.snapshot_url:
            with urllib.request.urlopen(self.snapshot_url, timeout=self.timeout) as response:  # noqa: S310
                image = response.read()
        return PlateRead(plate, int(round(float(data.get("confidence", 0)) * 10000)), image)


class SimulatedAnprCamera:
    """Returns the queued plates in order (None when empty). `misread` replaces one character, as a
    real camera does with dirty plates, to exercise the server's mismatch flag."""

    def __init__(self, plates: list[str] | None = None, confidence_bp: int = 9600) -> None:
        self.plates = list(plates or [])
        self.confidence_bp = confidence_bp

    def queue(self, plate: str, misread: bool = False) -> None:
        if misread and plate:
            last = plate[-1]
            plate = plate[:-1] + ("8" if last != "8" else "3")
        self.plates.append(plate)

    def read(self) -> PlateRead | None:
        if not self.plates:
            return None
        plate = self.plates.pop(0)
        return PlateRead(plate, self.confidence_bp, f"plate:{plate}".encode())
