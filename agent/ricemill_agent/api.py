"""HTTPS client for /api/v1 with device-bound tokens and signed requests (stdlib only)."""

from __future__ import annotations

import json
import urllib.error
import urllib.request
import uuid
from dataclasses import dataclass
from typing import Protocol

from .signing import sign


class ApiError(Exception):
    def __init__(self, status: int, body: dict) -> None:
        super().__init__(f"HTTP {status}: {body.get('error') or body.get('message')}")
        self.status = status
        self.body = body


# Server answers that mean "stop and tell the operator", not "sign in again".
STOP_REASONS = {"system_locked", "access_disabled", "device_revoked", "maintenance"}


def stop_reason(error: "ApiError") -> str | None:
    """The reason when the server has stopped this agent on purpose (lockdown, account or device withdrawn)."""
    for key in ("reason", "error"):
        value = error.body.get(key)
        if value in STOP_REASONS:
            return str(value)
    if error.status == 503:
        return "maintenance"
    return None


class OfflineError(Exception):
    """The server could not be reached (network down, DNS, timeout)."""


class Transport(Protocol):
    def request(self, method: str, url: str, headers: dict[str, str], body: bytes) -> tuple[int, bytes]: ...


class UrllibTransport:
    def __init__(self, timeout: float = 15.0) -> None:
        self.timeout = timeout

    def request(self, method: str, url: str, headers: dict[str, str], body: bytes) -> tuple[int, bytes]:
        req = urllib.request.Request(url, data=body if method != "GET" else None, method=method, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:  # noqa: S310 - configured server URL
                return resp.status, resp.read()
        except urllib.error.HTTPError as e:
            return e.code, e.read()
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            raise OfflineError(str(e)) from e


@dataclass
class Credentials:
    username: str
    password: str
    device_uid: str
    signing_secret: str


class ApiClient:
    PREFIX = "/api/v1"

    def __init__(self, base_url: str, credentials: Credentials, transport: Transport | None = None) -> None:
        if not base_url.startswith("https://") and not base_url.startswith("http://127.0.0.1") and not base_url.startswith("http://localhost"):
            raise ValueError("The server must be reached over HTTPS.")
        self.base = base_url.rstrip("/")
        self.creds = credentials
        self.transport = transport or UrllibTransport()
        self.access: str | None = None
        self.refresh_token: str | None = None

    # ------------------------------------------------------------------ auth

    def login(self) -> dict:
        body = self._send("POST", "/auth/login", {
            "username": self.creds.username, "password": self.creds.password,
            "device_uid": self.creds.device_uid, "device_name": "Gate agent", "platform": "agent",
        }, auth=False)
        if body.get("status") != "ok":
            raise ApiError(401, {"error": body.get("status", "login_failed")})
        self.access, self.refresh_token = body["access_token"], body["refresh_token"]
        return body

    def _refresh(self) -> bool:
        if not self.refresh_token:
            return False
        try:
            body = self._send("POST", "/auth/refresh", {"refresh_token": self.refresh_token}, auth=False)
        except ApiError:
            return False
        self.access, self.refresh_token = body["access_token"], body["refresh_token"]
        return True

    # ------------------------------------------------------------------ endpoints

    def bootstrap(self) -> dict:
        return self.call("GET", "/sync/bootstrap")

    def push(self, events: list[dict]) -> dict:
        return self.call("POST", "/sync/push", {"events": events})

    def pull(self, cursor: int, limit: int = 500) -> dict:
        return self.call("GET", f"/sync/pull?cursor={cursor}&limit={limit}")

    def wipe_ack(self) -> dict:
        return self.call("POST", "/sync/wipe-ack", {})

    def upload(self, data: bytes, mime: str) -> dict:
        """Raw body upload: the exact bytes are signed (a multipart body could not be verified)."""
        return self.call("POST", "/files", raw=data, content_type=mime)

    # ------------------------------------------------------------------ plumbing

    def call(self, method: str, path: str, json_body: dict | None = None, raw: bytes | None = None, content_type: str = "application/json") -> dict:
        """Authenticated call: logs in on demand, refreshes once on 401."""
        if self.access is None:
            self.login()
        try:
            return self._send(method, path, json_body, raw=raw, content_type=content_type)
        except ApiError as e:
            if stop_reason(e) is not None:
                raise
            if e.status == 401 and e.body.get("error") not in {"signature_invalid", "nonce_reused", "signature_missing_or_expired"}:
                if not self._refresh():
                    self.login()
                return self._send(method, path, json_body, raw=raw, content_type=content_type)
            raise

    def _send(self, method: str, path: str, json_body: dict | None = None, auth: bool = True, raw: bytes | None = None,
              content_type: str = "application/json") -> dict:
        body = raw if raw is not None else (json.dumps(json_body).encode() if json_body is not None and method != "GET" else b"")
        uri = self.PREFIX + path
        headers = {"Accept": "application/json", "X-Device-Id": self.creds.device_uid}
        if body:
            headers["Content-Type"] = content_type
        if auth and self.access:
            headers["Authorization"] = f"Bearer {self.access}"
            headers.update(sign(self.creds.signing_secret, method, uri, body))

        status, payload = self.transport.request(method, self.base + uri, headers, body)
        data = json.loads(payload) if payload else {}
        if status >= 400:
            raise ApiError(status, data if isinstance(data, dict) else {})
        return data
