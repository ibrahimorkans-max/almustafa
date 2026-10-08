"""Boom barriers. The barrier is opened only on the server's "open" answer or a guard's manual
open with a reason; with no answer it stays closed (fail closed)."""

from __future__ import annotations

import socket
from typing import Protocol


class Barrier(Protocol):
    def open(self) -> None: ...

    def close(self) -> None: ...


class TcpRelayBarrier:  # pragma: no cover - needs hardware
    """Network relay boards (common on gate controllers) take a short text command per port."""

    def __init__(self, host: str, port: int, open_command: str = "OPEN\n", close_command: str = "CLOSE\n", timeout: float = 3.0) -> None:
        self.host, self.port = host, port
        self.open_command, self.close_command = open_command, close_command
        self.timeout = timeout

    def _send(self, command: str) -> None:
        with socket.create_connection((self.host, self.port), timeout=self.timeout) as s:
            s.sendall(command.encode())

    def open(self) -> None:
        self._send(self.open_command)

    def close(self) -> None:
        self._send(self.close_command)


class SimulatedBarrier:
    """Records commands; `fail=True` simulates a dead relay."""

    def __init__(self, fail: bool = False) -> None:
        self.commands: list[str] = []
        self.is_open = False
        self.fail = fail

    def open(self) -> None:
        if self.fail:
            raise OSError("relay not responding")
        self.commands.append("open")
        self.is_open = True

    def close(self) -> None:
        self.commands.append("close")
        self.is_open = False
