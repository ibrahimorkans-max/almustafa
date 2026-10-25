"""Command line: `ricemill-agent --config agent.toml <command>`.

    status                       unsynced events, last cursor
    sync                         upload photos, push events, pull the feed
    arrive REG [--sauda ID]      record a vehicle arrival (works offline)
    capture ENTRY gross|tare     wait for a stable weight, photograph, queue
    simulate REG GROSS TARE      full inbound weighing with the simulated indicator
    gate in|out [--entry ID]     plate read + server decision; opens the barrier only on yes
    manual-open in|out REASON    guard override (recorded and flagged)
    check                        test everything in order (settings, indicator, ERP, sign-in, live weight) and say what to fix
    live [--simulate KG]         send the indicator reading about once a second for the screens
    ports                        list the COM (serial) ports of this PC
    detect [--port COM3] [--write]   find the indicator's port, speed and format (and write them to agent.toml)
    watch [--seconds 30]         show the indicator's readings as the agent reads them
    cameras                      list the USB cameras Windows sees (needs ffmpeg)
    camera-test [--out FILE]     take one photo with the configured camera
    new-store-key                print a fresh encryption key for [store].key
"""

from __future__ import annotations

import argparse
import getpass
import json
import sys
from decimal import Decimal

from .api import ApiClient, Credentials
from .config import AgentConfig
from .drivers.anpr import HttpAnprCamera, SimulatedAnprCamera
from .drivers.barrier import SimulatedBarrier, TcpRelayBarrier
from .drivers.camera import FfmpegCamera, HttpSnapshotCamera, SimulatedCamera, list_usb_cameras
from .drivers.display import SimulatedDisplay, TcpTextDisplay
from .drivers.indicator import SerialIndicator, SimulatedIndicator, TcpIndicator, make_parser
from .gate import GateAgent
from .gate_control import GateController
from .doctor import explain, kg_text, reach_server, run_check
from .live import LiveWeightStreamer
from . import secrets
from .queue import LocalStore
from .sync import SyncWorker


def indicator_from(section: dict, resolution_kg: Decimal = Decimal("10"), simulated_target: Decimal | None = None):
    kind = section.get("type", "simulated")
    parser = make_parser(section.get("format"), section.get("frame_pattern"))
    if kind == "serial":
        return SerialIndicator(section["port"], int(section.get("baudrate", 9600)), parser,
                               int(section.get("bytesize", 8)), str(section.get("parity", "none")), int(section.get("stopbits", 1)))
    if kind == "tcp":
        return TcpIndicator(section["host"], int(section["port"]), parser)
    return SimulatedIndicator(simulated_target or Decimal("25000"), resolution_kg)


def build_indicator(cfg: AgentConfig, simulated_target: Decimal | None = None):
    return indicator_from(cfg.indicator, cfg.resolution_kg, simulated_target)


def camera_from(section: dict):
    kind = section.get("type", "simulated")
    if kind == "http":
        return HttpSnapshotCamera(section["url"])
    if kind == "usb":
        return FfmpegCamera("usb", section["device"], section.get("ffmpeg", "ffmpeg"))
    if kind == "rtsp":
        return FfmpegCamera("rtsp", section["url"], section.get("ffmpeg", "ffmpeg"))
    if kind == "none":
        return None
    return SimulatedCamera()


def build_camera(cfg: AgentConfig):
    return camera_from(cfg.camera)


def write_indicator(path: str, block: str) -> None:
    """Replaces the [indicator] section of agent.toml (or adds it) with the detected settings."""
    import re
    from pathlib import Path

    file = Path(path)
    text = file.read_text(encoding="utf-8-sig") if file.exists() else ""
    # The section runs to the next line that starts a section; a "[" inside a comment does not end it.
    section = re.compile(r"^\[indicator\][^\n]*\n(?:(?!\[)[^\n]*(?:\n|\Z))*", re.M)
    text = section.sub(lambda _m: block + "\n\n", text, count=1) if section.search(text) else text.rstrip() + "\n\n" + block + "\n"
    file.write_text(text, encoding="utf-8")


def hardware_section(path: str, name: str) -> dict:
    """[indicator] / [camera] from agent.toml without the secrets (for watch and camera-test before set-up)."""
    import tomllib
    from pathlib import Path

    return tomllib.loads(Path(path).read_text(encoding="utf-8-sig")).get(name, {}) if Path(path).exists() else {}


def build_gate(cfg: AgentConfig, store: LocalStore, api: ApiClient, simulated_plate: str | None = None) -> GateController:
    anpr = None
    if cfg.anpr.get("type") == "http":
        anpr = HttpAnprCamera(cfg.anpr["result_url"], cfg.anpr.get("snapshot_url"))
    elif cfg.anpr.get("type") == "simulated" or simulated_plate:
        anpr = SimulatedAnprCamera([simulated_plate] if simulated_plate else [])
    if cfg.barrier.get("type") == "tcp":
        barrier = TcpRelayBarrier(cfg.barrier["host"], int(cfg.barrier["port"]),
                                  cfg.barrier.get("open_command", "OPEN\n"), cfg.barrier.get("close_command", "CLOSE\n"))
    else:
        barrier = SimulatedBarrier()
    display = None
    if cfg.display.get("type") == "tcp":
        display = TcpTextDisplay(cfg.display["host"], int(cfg.display["port"]), int(cfg.display.get("width", 16)))
    elif cfg.display.get("type") == "simulated":
        display = SimulatedDisplay(int(cfg.display.get("width", 16)))
    return GateController(store, api, anpr, barrier, display)


def main(argv: list[str] | None = None) -> int:
    """Runs a command; any problem is printed as one plain sentence and what to do, not a Python traceback."""
    for stream in (sys.stdout, sys.stderr):
        try:  # a Windows console cannot show every character; show "?" rather than fail
            stream.reconfigure(errors="replace")
        except (AttributeError, ValueError):
            pass
    try:
        return _main(argv)
    except KeyboardInterrupt:
        print("\nStopped.")
        return 130
    except Exception as e:  # noqa: BLE001 - shown to the operator in plain words
        print("\n" + str(explain(e)), file=sys.stderr)
        print("Run  .venv\\Scripts\\ricemill-agent --config agent.toml check  to test every step.", file=sys.stderr)
        return 1


def _main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="ricemill-agent")
    p.add_argument("--config", default="agent.toml")
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("check", help="test every step from the indicator to the Live weight screen")
    sub.add_parser("status")
    sub.add_parser("sync")
    sub.add_parser("new-store-key")
    pr = sub.add_parser("protect", help="DPAPI-protect a secret for agent.toml (Windows); reads it from the keyboard")
    pr.add_argument("--scope", choices=["machine", "user"], default="machine")
    a = sub.add_parser("arrive")
    a.add_argument("registration_no")
    a.add_argument("--sauda")
    a.add_argument("--bags", type=int)
    c = sub.add_parser("capture")
    c.add_argument("gate_entry_id")
    c.add_argument("kind", choices=["gross", "tare"])
    s = sub.add_parser("simulate")
    s.add_argument("registration_no")
    s.add_argument("gross", type=Decimal)
    s.add_argument("tare", type=Decimal)
    s.add_argument("--sauda")
    g = sub.add_parser("gate")
    g.add_argument("side", choices=["in", "out"])
    g.add_argument("--entry")
    g.add_argument("--plate", help="simulated ANPR: plate to 'see'")
    m = sub.add_parser("manual-open")
    m.add_argument("side", choices=["in", "out"])
    m.add_argument("reason")
    m.add_argument("--entry")
    lv = sub.add_parser("live")
    lv.add_argument("--simulate", type=Decimal, help="simulated indicator: a truck of this many kg, again and again")
    lv.add_argument("--count", type=int, help="stop after this many sends (default: run until stopped)")
    sub.add_parser("ports")
    d = sub.add_parser("detect")
    d.add_argument("--port", help="only this port, e.g. COM3 (default: every port)")
    d.add_argument("--seconds", type=float, default=3.0, help="listening time per speed")
    d.add_argument("--write", action="store_true", help="write the result into the [indicator] section of the config file")
    w = sub.add_parser("watch")
    w.add_argument("--seconds", type=float, default=30.0)
    sub.add_parser("cameras")
    ct = sub.add_parser("camera-test")
    ct.add_argument("--out", default="camera-test.jpg")
    args = p.parse_args(argv)

    if args.cmd in ("ports", "detect", "watch", "cameras", "camera-test"):
        return hardware_command(args)
    if args.cmd == "check":
        print("Checking every step from the indicator to the Live weight screen...\n")
        steps = run_check(
            args.config, load_config=AgentConfig.load,
            make_api=lambda cfg: ApiClient(cfg.server_url, Credentials(cfg.username, cfg.password, cfg.device_uid, cfg.signing_secret)),
            make_samples=lambda cfg: build_indicator(cfg).samples(), reach=reach_server,
        )
        return 0 if steps and all(s.ok for s in steps) and steps[-1].name == "Live weight" else 1

    if args.cmd == "new-store-key":
        print(LocalStore.new_key().decode())
        return 0
    if args.cmd == "protect":
        value = getpass.getpass("Secret to protect (not shown): ")
        if not value:
            print("Nothing entered.", file=sys.stderr)
            return 1
        print(secrets.protect(value, args.scope))
        return 0

    cfg = AgentConfig.load(args.config)
    store = LocalStore(cfg.store_path, cfg.store_key)
    api = ApiClient(cfg.server_url, Credentials(cfg.username, cfg.password, cfg.device_uid, cfg.signing_secret))

    if args.cmd == "status":
        print(json.dumps({"unsynced": store.unsynced_count(), "cursor": store.get_meta("cursor", "0"), "stopped": store.get_meta("stopped", "") or None}))
        return 0
    if args.cmd == "sync":
        print(json.dumps(SyncWorker(store, api).run().__dict__, default=str))
        return 0
    if args.cmd == "arrive":
        agent = GateAgent(store, build_indicator(cfg), build_camera(cfg), cfg.scale_id, cfg.resolution_kg, cfg.min_stable_samples)
        print(agent.arrive(args.registration_no, args.sauda, args.bags))
        return 0
    if args.cmd == "capture":
        agent = GateAgent(store, build_indicator(cfg), build_camera(cfg), cfg.scale_id, cfg.resolution_kg, cfg.min_stable_samples)
        result = agent.capture(args.gate_entry_id, args.kind)
        print(json.dumps({"weighment_id": result.weighment_id, "weight_kg": str(result.reading.weight_kg), "photo": result.photo_sha256}))
        return 0
    if args.cmd == "simulate":
        camera = build_camera(cfg)
        gross_agent = GateAgent(store, SimulatedIndicator(args.gross, cfg.resolution_kg), camera, cfg.scale_id, cfg.resolution_kg, cfg.min_stable_samples)
        entry = gross_agent.arrive(args.registration_no, args.sauda)
        gross_agent.capture(entry, "gross")
        print("gross queued; syncing:", SyncWorker(store, api).run().__dict__)
        print(f"entry {entry}: record the lab decision and unloading in the admin panel, then run:")
        print(f"  ricemill-agent --config {args.config} capture {entry} tare")
        return 0
    if args.cmd == "gate":
        result = build_gate(cfg, store, api, args.plate).request_passage(args.side, args.entry)
        print(json.dumps(result.__dict__))
        return 0 if result.opened else 2
    if args.cmd == "live":
        if args.simulate is not None:
            def repeat():
                while True:
                    yield from SimulatedIndicator(args.simulate, cfg.resolution_kg).samples()
            samples = repeat()
        else:
            samples = build_indicator(cfg).samples()
        print(f"Live weight for {cfg.server_url} - leave this window open (Ctrl+C stops it).")
        stats = LiveWeightStreamer(samples, api, cfg.scale_id, cfg.resolution_kg, report=lambda m: print(m, flush=True)).run(args.count)
        print(json.dumps({"readings": stats.readings, "sent": stats.sent, "dropped": stats.dropped, "last_error": stats.last_error}))
        return 1 if stats.stopped else 0
    if args.cmd == "manual-open":
        build_gate(cfg, store, api).manual_open(args.side, args.reason, args.entry)
        print("barrier opened manually; the event is queued for sync")
        return 0
    return 1


def hardware_command(args) -> int:  # pragma: no cover - needs the machine
    """Set-up helpers that need no server login: they work before agent.toml has its secrets."""
    import time

    from .detect import detect, serial_ports, serial_reader

    if args.cmd == "ports":
        ports = serial_ports()
        if not ports:
            print("No COM port found. Plug in the cable / USB-to-RS232 adapter and install its driver (CH340, PL2303 or FTDI).")
            return 1
        for device, description in ports:
            print(f"{device}\t{description}")
        return 0
    if args.cmd == "detect":
        ports = [args.port] if args.port else [d for d, _ in serial_ports()]
        if not ports:
            print("No COM port found. Plug in the cable first (see `ports`).")
            return 1
        print("Keep a weight on the scale (or change it) while the agent listens.")
        found = detect(ports, serial_reader(args.seconds), progress=print)
        if not found:
            print("No readings recognised. Check: the indicator's serial output is ON and set to CONTINUOUS, "
                  "the cable (try a null-modem adapter), and that no other program uses the port.")
            return 1
        best = found[0]
        print("\nFound: " + best.describe())
        print("\n" + best.config())
        if args.write:
            write_indicator(args.config, best.config())
            print(f"\nWritten to {args.config}.")
        return 0
    if args.cmd == "watch":
        indicator = indicator_from(hardware_section(args.config, "indicator"))
        end = time.monotonic() + args.seconds
        for weight, _ in indicator.samples():
            print(f"{time.strftime('%H:%M:%S')}  {kg_text(weight):>10} kg")
            if time.monotonic() > end:
                break
        return 0
    if args.cmd == "cameras":
        names = list_usb_cameras(hardware_section(args.config, "camera").get("ffmpeg", "ffmpeg"))
        print("\n".join(names) if names else "No USB camera found (is ffmpeg installed and the camera plugged in?).")
        return 0 if names else 1
    if args.cmd == "camera-test":
        camera = camera_from(hardware_section(args.config, "camera"))
        if camera is None:
            print('[camera] type is "none".')
            return 1
        photo = camera.capture()
        with open(args.out, "wb") as f:
            f.write(photo)
        print(f"Photo saved: {args.out} ({len(photo) // 1024} KB). Open it to check the view of the platform and number plate.")
        return 0
    return 1


if __name__ == "__main__":
    sys.exit(main())
