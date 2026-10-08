"""Display boards at the weighbridge and the barrier (two lines of text)."""

from __future__ import annotations

import socket
from typing import Protocol


class Display(Protocol):
    def show(self, line1: str, line2: str = "") -> None: ...


class TcpTextDisplay:  # pragma: no cover - needs hardware
    """LED boards with a plain text protocol: one line per message, lines separated by `|`."""

    def __init__(self, host: str, port: int, width: int = 16, timeout: float = 3.0) -> None:
        self.host, self.port, self.width, self.timeout = host, port, width, timeout

    def show(self, line1: str, line2: str = "") -> None:
        text = f"{line1[: self.width]}|{line2[: self.width]}\n"
        with socket.create_connection((self.host, self.port), timeout=self.timeout) as s:
            s.sendall(text.encode())


class SimulatedDisplay:
    def __init__(self, width: int = 16) -> None:
        self.width = width
        self.history: list[tuple[str, str]] = []

    def show(self, line1: str, line2: str = "") -> None:
        self.history.append((line1[: self.width], line2[: self.width]))

    @property
    def current(self) -> tuple[str, str] | None:
        return self.history[-1] if self.history else None
