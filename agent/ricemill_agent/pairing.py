"""Pairing the PC with the ERP using one code (`ricemill-agent pair`), and saving a checked login (`login`).

The office makes a code in the ERP (Security -> Devices -> Pair a weighbridge PC). The PC sends it once to
POST /api/v1/agent/pair and receives its login, password, device_uid, signing secret and weighbridge; they
are written into agent.toml (secrets DPAPI-protected on Windows) and the sign-in is tested at once.
Only the values are changed in agent.toml: the indicator, camera and other sections stay as they are.
"""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Callable

from .api import ApiError, Transport, UrllibTransport


def normalize_server(url: str) -> str:
    """'millnexa.example.com/admin' -> 'https://millnexa.example.com' (only the address is wanted)."""
    url = url.strip().strip('"').rstrip("/")
    if not re.match(r"^https?://", url, re.I):
        url = "https://" + url
    m = re.match(r"^(https?://[^/?#]+)", url, re.I)
    return m.group(1).lower() if m else url


def normalize_code(code: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", code.upper())


def request_pairing(server: str, code: str, transport: Transport | None = None) -> dict:
    """The settings for a pairing code; raises ApiError (422 pairing_code_invalid) or OfflineError."""
    transport = transport or UrllibTransport()
    body = json.dumps({"code": normalize_code(code)}).encode()
    status, payload = transport.request("POST", server.rstrip("/") + "/api/v1/agent/pair",
                                        {"Accept": "application/json", "Content-Type": "application/json"}, body)
    try:
        data = json.loads(payload) if payload else {}
    except ValueError:
        data = {}
    if not isinstance(data, dict):
        data = {}
    if status == 404 and "error" not in data:
        raise ApiError(404, {"error": "pairing_not_supported",
                             "message": "this ERP does not offer pairing yet (or the address is wrong)"})
    if status >= 400:
        raise ApiError(status, data)
    for key in ("username", "password", "device_uid", "signing_secret", "scale_id"):
        if not data.get(key):
            raise ApiError(500, {"message": f"the ERP's answer has no {key}"})
    return data


def _toml_string(value: str) -> str:
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def set_values(text: str, values: dict[str, dict[str, str]]) -> str:
    """Sets `key = "value"` in each [section], keeping comments after the value and every other line."""
    newline = "\r\n" if "\r\n" in text else "\n"
    lines = text.splitlines()
    for section, pairs in values.items():
        start = next((i for i, line in enumerate(lines) if line.strip() == f"[{section}]"), None)
        if start is None:
            if lines and lines[-1].strip():
                lines.append("")
            lines.append(f"[{section}]")
            start = len(lines) - 1
        end = next((i for i in range(start + 1, len(lines)) if lines[i].lstrip().startswith("[")), len(lines))
        for key, value in pairs.items():
            pattern = re.compile(rf"^(\s*{re.escape(key)}\s*=\s*)(\"(?:[^\"\\]|\\.)*\"|'[^']*'|[^\s#]+)?(.*)$")
            for i in range(start + 1, end):
                m = pattern.match(lines[i])
                if m:
                    rest = m.group(3)
                    lines[i] = m.group(1) + _toml_string(value) + (rest if rest.strip().startswith("#") else "")
                    break
            else:
                lines.insert(start + 1, f"{key} = {_toml_string(value)}")
                end += 1
    return newline.join(lines) + newline


def read_config_text(path: Path, example: Path) -> str:
    """The config (or the example to start from); a file an editor saved in another encoding is still read."""
    for candidate in (path, example):
        if candidate.exists():
            return candidate.read_bytes().decode("utf-8-sig", errors="replace")
    return ""


def write_config_text(path: Path, text: str) -> None:
    # UTF-8 without a byte-order mark (some editors add one; the agent accepts both).
    path.write_text(text, encoding="utf-8", newline="")


def current_value(text: str, section: str, key: str) -> str:
    in_section = False
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith("["):
            in_section = stripped == f"[{section}]"
            continue
        m = re.match(rf"^{re.escape(key)}\s*=\s*\"((?:[^\"\\]|\\.)*)\"", stripped)
        if in_section and m:
            return m.group(1)
    return ""


def apply_pairing(text: str, server: str, settings: dict, protect: Callable[[str], str], new_store_key: Callable[[], str]) -> str:
    values: dict[str, dict[str, str]] = {
        "server": {"url": server},
        "auth": {
            "username": settings["username"],
            "password": protect(settings["password"]),
            "device_uid": settings["device_uid"],
            "signing_secret": protect(settings["signing_secret"]),
        },
        "scale": {"id": settings["scale_id"]},
    }
    if settings.get("resolution_kg"):
        values["scale"]["resolution_kg"] = str(settings["resolution_kg"])
    if not current_value(text, "store", "key"):
        values["store"] = {"key": protect(new_store_key())}
    return set_values(text, values)
