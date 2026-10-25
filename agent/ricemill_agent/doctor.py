"""Plain-language problems for the weighbridge PC, and `ricemill-agent check`.

`explain` turns any error the agent can meet (settings file, secrets, indicator, network, server
answers) into one line saying what is wrong and one saying what to do, instead of a Python traceback.
`run_check` tests the whole chain in order - settings, secrets, indicator, server, sign-in,
weighbridge, live weight - and stops at the first step that fails, with its fix.
"""

from __future__ import annotations

import time
import tomllib
import urllib.error
import urllib.request
from dataclasses import dataclass
from decimal import Decimal
from pathlib import Path
from typing import Callable, Iterator

from .api import ApiClient, ApiError, OfflineError


def kg_text(weight) -> str:
    """25000 -> "25000", 12.50 -> "12.5" (never the 2.5E+4 form)."""
    return format(weight.normalize(), "f")


@dataclass
class Problem:
    what: str
    fix: str

    def __str__(self) -> str:
        return f"PROBLEM: {self.what}\nWHAT TO DO: {self.fix}"


# Server error codes -> what to do on the PC or in the ERP.
SERVER_ANSWERS = {
    "invalid_credentials": ("The ERP refused the PC's username or password.",
                            "Sign in once on the ERP website with the PC's login (e.g. wb.agent) to check the password, then run setup-windows.ps1 again to save it."),
    "password_change_required": ("The PC's login still has its first (temporary) password.",
                                 "Sign in once on the ERP website with the PC's login and set a new password, then run setup-windows.ps1 again to save it."),
    "mfa_enrollment_required": ("The PC's login is asked for two-factor sign-in, which a PC cannot do.",
                                "In the ERP, give the PC's login the role 'Weighbridge / Gate Operator' only, or turn off two-factor for that login."),
    "mfa_required": ("The PC's login is asked for a two-factor code, which a PC cannot type.",
                     "In the ERP, turn off two-factor for the PC's login (it is protected by its registered device instead)."),
    "account_locked": ("The PC's login is locked (too many wrong passwords).",
                       "In the ERP: Users -> the PC's login -> unlock it. Then check the password saved on the PC."),
    "access_disabled": ("The PC's login is switched off in the ERP.", "In the ERP: Users -> the PC's login -> set it Active."),
    "device_revoked": ("This PC's registration was withdrawn in the ERP.",
                       "In the ERP: Security -> Devices -> Register gate agent again, then run setup-windows.ps1 with the new device_uid and secret."),
    "device_not_allowed": ("The ERP does not know this PC as a gate agent for this login (or its registration was withdrawn).",
                           "In the ERP: Security -> Devices -> Register gate agent for the PC's login; put that device_uid and secret on the PC (run setup-windows.ps1 again)."),
    "device_pending_approval": ("This PC is waiting for approval in the ERP.", "In the ERP: open Approvals and approve the new device, then run this again."),
    "network_not_allowed": ("The ERP does not accept sign-ins from this PC's internet address.",
                            "In the ERP: Security -> allowed networks -> add this mill's internet address (or ask the owner)."),
    "signed_out": ("The ERP signed this login out.", "Sign in on the ERP website with the PC's login to see why, then run this again."),
    "signature_invalid": ("The ERP does not recognise this PC's signing secret.",
                          "The secret on the PC does not match the ERP. Register the gate agent again (Security -> Devices) and run setup-windows.ps1 with the new secret."),
    "system_locked": ("The ERP is locked by the owner (panic lock).", "Ask the owner to unlock the system."),
    "maintenance": ("The ERP is in maintenance.", "Wait and try again later."),
}


def explain(error: BaseException, server_url: str = "") -> Problem:
    """One plain sentence for any error the agent can meet."""
    if isinstance(error, KeyboardInterrupt):
        return Problem("Stopped by Ctrl+C before it finished.", "Run it again and wait: the agent gives up by itself after 15 seconds and says why.")
    if isinstance(error, FileNotFoundError):
        return Problem(f"File not found: {error.filename}.", "Run the command inside C:\\RiceMillAgent, or give the full path with --config.")
    if isinstance(error, tomllib.TOMLDecodeError):
        return Problem(f"agent.toml has a mistake: {error}.",
                       "Open it with: notepad agent.toml - go to that line and fix or delete it (every setting is: name = \"value\").")
    if isinstance(error, KeyError):
        return Problem(f"agent.toml is missing the setting {error}.", "Copy agent.example.toml again, or add that line (see Connect a weighbridge -> Details to copy).")
    if isinstance(error, OfflineError):
        text = str(error).lower()
        if "timed out" in text or "timeout" in text:
            return Problem(f"The ERP server ({server_url}) did not answer within 15 seconds.",
                           "Check the PC's internet and that the ERP website opens in a browser on this PC. If the website is slow too, the server is busy or down.")
        if "getaddrinfo" in text or "name or service" in text or "nodename" in text:
            return Problem(f"The ERP address ({server_url}) cannot be found.", "Check the url in agent.toml (spelling, https://) and the PC's internet.")
        if "certificate" in text or "ssl" in text:
            return Problem("The ERP's security certificate was refused.", "Check the PC's date and time are correct, and that the url in agent.toml is the real ERP address.")
        if "refused" in text:
            return Problem(f"The ERP server ({server_url}) refused the connection.", "The server is not running or the address/port is wrong.")
        return Problem(f"The ERP server could not be reached ({error}).", "Check the PC's internet and the url in agent.toml.")
    if isinstance(error, ApiError):
        code = str(error.body.get("error") or error.body.get("reason") or "")
        if code in SERVER_ANSWERS:
            what, fix = SERVER_ANSWERS[code]
            return Problem(what, fix)
        message = str(error.body.get("message") or code or "no details")
        if error.status == 401:
            return Problem(f"The ERP refused the sign-in: {message}", SERVER_ANSWERS["invalid_credentials"][1])
        if error.status == 403:
            return Problem(f"The ERP does not allow this: {message}",
                           "The PC's login needs the role 'Weighbridge / Gate Operator', and this PC must be an approved device (Approvals).")
        if error.status == 404:
            return Problem("The ERP address answers, but not as the ERP.", "Check the url in agent.toml: only the address, e.g. https://erp.example.com (no /admin).")
        if error.status == 422:
            return Problem(f"The ERP did not accept the data: {message}", "If it is about the weighbridge, copy its id again (Connect a weighbridge -> Details to copy) into [scale] id.")
        if error.status == 429:
            return Problem("Too many requests to the ERP in a short time.", "Wait a minute and try again; run only one 'live' window.")
        if error.status >= 500:
            return Problem(f"The ERP server had an error (HTTP {error.status}).", "Try again in a minute; if it stays, the server needs checking.")
        return Problem(f"The ERP answered HTTP {error.status}: {message}", "Send this message to the ERP administrator.")
    text = str(error)
    if "Missing secret" in text:
        return Problem("A secret is missing in agent.toml (password, signing_secret or store key).", "Run setup-windows.ps1 again; it asks for them and saves them protected.")
    if "dpapi" in text.lower() or "decrypt" in text.lower() or "CryptUnprotectData" in text:
        return Problem("A protected secret in agent.toml cannot be opened on this PC.",
                       "Secrets are protected for the PC they were made on. Run setup-windows.ps1 again on this PC.")
    if "HTTPS" in text:
        return Problem("The url in agent.toml must start with https://.", "Fix the url line in agent.toml.")
    if "could not open port" in text.lower() or "PermissionError" in text or "Access is denied" in text:
        return Problem(f"The COM port cannot be opened ({text}).",
                       "Another program is using it (close other weighbridge software or a second agent window), or the cable was unplugged.")
    return Problem(f"{type(error).__name__}: {text}", "Send this message to the ERP administrator.")


# ---------------------------------------------------------------------------- check

PLACEHOLDERS = ("erp.example.in", "agent-xxxx", "…")   # the sample values of agent.example.toml


@dataclass
class Step:
    name: str
    ok: bool
    detail: str
    fix: str = ""


def _placeholder(value: str) -> bool:
    return value.strip() == "" or any(p in value for p in PLACEHOLDERS)


def run_check(config_path: str, *, load_config: Callable, make_api: Callable[[object], ApiClient],
              make_samples: Callable[[object], Iterator[tuple[Decimal, int]]], reach: Callable[[str], float] | None = None,
              read_seconds: float = 5.0, out: Callable[[str], None] = print) -> list[Step]:
    """Every link from the indicator to the Live weight screen, in order; stops at the first broken one."""
    steps: list[Step] = []

    def report(step: Step) -> bool:
        steps.append(step)
        out(f"[{'OK' if step.ok else 'X '}] {step.name}: {step.detail}")
        if not step.ok and step.fix:
            out(f"      WHAT TO DO: {step.fix}")
        return step.ok

    # 1. The settings file.
    path = Path(config_path)
    if not path.exists():
        report(Step("Settings file", False, f"{path.resolve()} not found", "Run this inside C:\\RiceMillAgent, or run setup-windows.ps1 first."))
        return steps
    try:
        data = tomllib.loads(path.read_text(encoding="utf-8-sig"))
    except tomllib.TOMLDecodeError as e:
        p = explain(e)
        report(Step("Settings file", False, p.what, p.fix))
        return steps
    report(Step("Settings file", True, str(path.resolve())))

    # 2. The values copied from the ERP.
    server, auth, scale = data.get("server", {}), data.get("auth", {}), data.get("scale", {})
    missing = [name for name, value in (("[server] url", server.get("url", "")), ("[auth] username", auth.get("username", "")),
                                        ("[auth] device_uid", auth.get("device_uid", "")), ("[scale] id", scale.get("id", "")))
               if _placeholder(str(value))]
    if missing:
        report(Step("Values from the ERP", False, "not filled in: " + ", ".join(missing),
                    "Copy them from the ERP: Gate & weighbridge -> Connect a weighbridge -> Details to copy (or run setup-windows.ps1 again)."))
        return steps
    try:
        cfg = load_config(config_path)
    except Exception as e:  # noqa: BLE001 - every problem becomes a sentence
        p = explain(e)
        report(Step("Secrets", False, p.what, p.fix))
        return steps
    report(Step("Values from the ERP", True, f"server {cfg.server_url}, login {cfg.username}, weighbridge {cfg.scale_id}"))

    # 3. The indicator.
    readings: list[Decimal] = []
    try:
        end = time.monotonic() + read_seconds
        for weight, _ in make_samples(cfg):
            readings.append(weight)
            if time.monotonic() > end or len(readings) >= 50:
                break
    except Exception as e:  # noqa: BLE001
        p = explain(e)
        report(Step("Indicator", False, p.what, p.fix))
        return steps
    if not readings:
        report(Step("Indicator", False, f"no reading in {read_seconds:g} seconds",
                    "Check the indicator is on and the cable is in; run: .venv\\Scripts\\ricemill-agent --config agent.toml detect --write"))
        return steps
    report(Step("Indicator", True, f"{len(readings)} readings, last {kg_text(readings[-1])} kg (must match the display)"))

    # 4. The server answers at all.
    if reach is not None:
        try:
            seconds = reach(cfg.server_url)
        except Exception as e:  # noqa: BLE001
            p = explain(e if isinstance(e, (OfflineError, ApiError)) else OfflineError(str(e)), cfg.server_url)
            report(Step("ERP server", False, p.what, p.fix))
            return steps
        report(Step("ERP server", True, f"answers in {seconds:.1f} s" + ("  (slow)" if seconds > 5 else "")))

    # 5. Sign-in, and 6. the weighbridge takes a live reading.
    api = make_api(cfg)
    try:
        api.login()
    except Exception as e:  # noqa: BLE001
        p = explain(e, cfg.server_url)
        report(Step("Sign-in", False, p.what, p.fix))
        return steps
    report(Step("Sign-in", True, f"signed in as {cfg.username}"))
    try:
        api.call("POST", "/gate/live-weight", {"scale_id": cfg.scale_id, "kg": str(readings[-1]), "stable": False})
    except Exception as e:  # noqa: BLE001
        p = explain(e, cfg.server_url)
        report(Step("Live weight", False, p.what, p.fix))
        return steps
    report(Step("Live weight", True, f"{kg_text(readings[-1])} kg sent - it shows on Gate & weighbridge -> Live weight"))
    out("\nAll good. Start live weight with: .venv\\Scripts\\ricemill-agent --config agent.toml live")
    return steps


def reach_server(url: str, timeout: float = 15.0) -> float:
    """Seconds the ERP takes to answer its health address (/up); raises OfflineError if it does not."""
    start = time.monotonic()
    try:
        with urllib.request.urlopen(url.rstrip("/") + "/up", timeout=timeout) as resp:  # noqa: S310 - configured server URL
            resp.read(64)
    except urllib.error.HTTPError as e:
        raise ApiError(e.code, {"message": f"health check answered HTTP {e.code}"}) from e
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        raise OfflineError(str(e)) from e
    return time.monotonic() - start
