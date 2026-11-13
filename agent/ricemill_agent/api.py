"""HTTPS client for /api/v1 with device-bound tokens and signed requests (stdlib only)."""

from __future__ import annotations

import hashlib
import http.client
import json
import ssl
import urllib.parse
import uuid
from dataclasses import dataclass
from pathlib import Path
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
    """One connection kept open to the ERP and reused: a send then takes one round trip, not a new
    TLS handshake each time (live weight sends twice a second). A broken connection is opened again once."""

    def __init__(self, timeout: float = 15.0) -> None:
        self.timeout = timeout
        self._conn: http.client.HTTPConnection | None = None
        self._origin: tuple[str, str, int | None] | None = None

    def _connection(self, parts: urllib.parse.SplitResult) -> http.client.HTTPConnection:
        origin = (parts.scheme, parts.hostname or "", parts.port)
        if self._conn is None or self._origin != origin:
            self.close()
            if parts.scheme == "https":
                self._conn = http.client.HTTPSConnection(parts.hostname, parts.port, timeout=self.timeout,
                                                         context=ssl.create_default_context())
            else:
                self._conn = http.client.HTTPConnection(parts.hostname, parts.port, timeout=self.timeout)
            self._origin = origin
        return self._conn

    def close(self) -> None:
        if self._conn is not None:
            self._conn.close()
        self._conn = None

    def request(self, method: str, url: str, headers: dict[str, str], body: bytes) -> tuple[int, bytes]:
        parts = urllib.parse.urlsplit(url)
        path = parts.path + (f"?{parts.query}" if parts.query else "")
        for attempt in (1, 2):
            conn = self._connection(parts)
            try:
                conn.request(method, path, body=body if method != "GET" else None, headers=headers)
                resp = conn.getresponse()
                data = resp.read()
                if resp.getheader("Connection", "").lower() == "close":
                    self.close()
                return resp.status, data
            except (http.client.HTTPException, ConnectionError, ssl.SSLError) as e:
                self.close()                          # the server closed the idle connection: open a new one
                if attempt == 2:
                    raise OfflineError(str(e)) from e
            except (TimeoutError, OSError) as e:
                self.close()
                raise OfflineError(str(e)) from e
        raise OfflineError("no answer")


@dataclass
class Credentials:
    username: str
    password: str
    device_uid: str
    signing_secret: str


class ApiClient:
    PREFIX = "/api/v1"

    def __init__(self, base_url: str, credentials: Credentials, transport: Transport | None = None,
                 refused_file: Path | None = None) -> None:
        if not base_url.startswith("https://") and not base_url.startswith("http://127.0.0.1") and not base_url.startswith("http://localhost"):
            raise ValueError("The server must be reached over HTTPS.")
        self.base = base_url.rstrip("/")
        self.creds = credentials
        self.transport = transport or UrllibTransport()
        self.access: str | None = None
        self.refresh_token: str | None = None
        # Unattended runs (sync every minute, live restarted on failure): a password the ERP refused is not sent
        # again until it changes. Each refusal counts against the login from the mill's internet address, which
        # every computer there shares: retrying would lock that login out for the people at the mill too.
        self.refused_file = refused_file

    def _fingerprint(self) -> str:
        return hashlib.sha256("\n".join((self.base, self.creds.username, self.creds.password)).encode()).hexdigest()

    # ------------------------------------------------------------------ auth

    def login(self) -> dict:
        if self.refused_file is not None and self.refused_file.is_file() \
                and self.refused_file.read_text(encoding="utf-8").strip() == self._fingerprint():
            raise ApiError(401, {"error": "invalid_credentials", "message": "The ERP refused this password before; "
                                 "it is not tried again until it is changed (ricemill-agent pair, or login)."})
        try:
            body = self._send("POST", "/auth/login", {
                "username": self.creds.username, "password": self.creds.password,
                "device_uid": self.creds.device_uid, "device_name": "Gate agent", "platform": "agent",
            }, auth=False)
        except ApiError as e:
            if self.refused_file is not None and e.body.get("error") == "invalid_credentials":
                self.refused_file.write_text(self._fingerprint(), encoding="utf-8")
            raise
        if self.refused_file is not None and self.refused_file.is_file():
            self.refused_file.unlink()
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
        # Every request is signed, sign-in included: at a mill that allows sign-in only inside its premises,
        # the server excuses the agent (no GPS) only when the sign-in carries this agent's signature.
        if self.creds.signing_secret:
            headers.update(sign(self.creds.signing_secret, method, uri, body))

        status, payload = self.transport.request(method, self.base + uri, headers, body)
        data = json.loads(payload) if payload else {}
        if status >= 400:
            raise ApiError(status, data if isinstance(data, dict) else {})
        return data
