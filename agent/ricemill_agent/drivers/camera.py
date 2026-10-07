"""Weighbridge cameras. The photo's SHA-256 is stored with the weighment, so the image can later
be proven to be the one taken at that moment."""

from __future__ import annotations

import struct
import urllib.request
import zlib
from typing import Protocol


class Camera(Protocol):
    def capture(self) -> bytes:
        """Returns a JPEG or PNG image."""
        ...


class HttpSnapshotCamera:  # pragma: no cover - needs a camera
    """Most IP cameras expose a snapshot URL (JPEG over HTTP, often with basic auth)."""

    def __init__(self, url: str, timeout: float = 5.0) -> None:
        self.url = url
        self.timeout = timeout

    def capture(self) -> bytes:
        with urllib.request.urlopen(self.url, timeout=self.timeout) as response:  # noqa: S310 - configured URL
            return response.read()


class SimulatedCamera:
    """Generates a small valid PNG whose pixels encode a counter, so every photo is unique."""

    def __init__(self) -> None:
        self.count = 0

    def capture(self) -> bytes:
        self.count += 1
        width, height = 8, 8
        shade = (self.count * 37) % 256
        raw = b"".join(b"\x00" + bytes([shade, 255 - shade, (shade * 7) % 256]) * width for _ in range(height))

        def chunk(kind: bytes, data: bytes) -> bytes:
            return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

        header = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
        return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", header) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")
