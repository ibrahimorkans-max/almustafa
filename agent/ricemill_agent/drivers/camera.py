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


class FfmpegCamera:
    """One still photo through ffmpeg (free, ffmpeg.org): a USB webcam on Windows (`usb`, DirectShow device name)
    or any IP camera / DVR / NVR stream (`rtsp`, e.g. rtsp://user:pass@192.168.1.64:554/Streaming/Channels/101).
    The JPEG comes back on standard output; nothing is written to disk."""

    def __init__(self, kind: str, source: str, ffmpeg: str = "ffmpeg", timeout: float = 15.0) -> None:
        if kind not in ("usb", "rtsp"):
            raise ValueError("FfmpegCamera kind is usb or rtsp")
        self.kind = kind
        self.source = source
        self.ffmpeg = ffmpeg
        self.timeout = timeout

    def command(self) -> list[str]:
        cmd = [self.ffmpeg, "-hide_banner", "-loglevel", "error", "-y"]
        if self.kind == "usb":
            cmd += ["-f", "dshow", "-i", f"video={self.source}"]
        else:
            cmd += ["-rtsp_transport", "tcp", "-i", self.source]
        return cmd + ["-frames:v", "1", "-q:v", "3", "-f", "image2", "-vcodec", "mjpeg", "pipe:1"]

    def capture(self) -> bytes:  # pragma: no cover - needs a camera and ffmpeg
        import subprocess

        result = subprocess.run(self.command(), capture_output=True, timeout=self.timeout, check=False)  # noqa: S603 - configured command
        if result.returncode != 0 or not result.stdout.startswith(b"\xff\xd8"):
            raise RuntimeError("Camera gave no picture: " + result.stderr.decode(errors="ignore").strip()[-300:])
        return result.stdout


def list_usb_cameras(ffmpeg: str = "ffmpeg") -> list[str]:  # pragma: no cover - needs Windows and ffmpeg
    """Names of the webcams Windows sees (for `[camera] device = "…"`)."""
    import subprocess

    out = subprocess.run([ffmpeg, "-hide_banner", "-list_devices", "true", "-f", "dshow", "-i", "dummy"],  # noqa: S603
                         capture_output=True, timeout=15, check=False).stderr.decode(errors="ignore")
    return parse_dshow_devices(out)


def parse_dshow_devices(text: str) -> list[str]:
    """Video device names from `ffmpeg -list_devices` output (old and new ffmpeg layouts)."""
    import re

    names: list[str] = []
    in_video = False
    for line in text.splitlines():
        if "DirectShow video devices" in line:
            in_video = True
            continue
        if "DirectShow audio devices" in line:
            in_video = False
        m = re.search(r'"([^"]+)"\s*(\((video|none|audio)\))?\s*$', line)
        if m and "Alternative name" not in line and (in_video or m.group(3) == "video"):
            names.append(m.group(1))
    return list(dict.fromkeys(names))


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
