"""Command line: `ricemill-agent --config agent.toml <command>`.

    status                       unsynced events, last cursor
    sync                         upload photos, push events, pull the feed
    arrive REG [--sauda ID]      record a vehicle arrival (works offline)
    capture ENTRY gross|tare     wait for a stable weight, photograph, queue
    simulate REG GROSS TARE      full inbound weighing with the simulated indicator
    gate in|out [--entry ID]     plate read + server decision; opens the barrier only on yes
    manual-open in|out REASON    guard override (recorded and flagged)
    live [--simulate KG]         send the indicator reading about once a second for the screens
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
from .drivers.camera import HttpSnapshotCamera, SimulatedCamera
from .drivers.display import SimulatedDisplay, TcpTextDisplay
from .drivers.indicator import FrameParser, SerialIndicator, SimulatedIndicator, TcpIndicator
from .gate import GateAgent
from .gate_control import GateController
from .live import LiveWeightStreamer
from . import secrets
from .queue import LocalStore
from .sync import SyncWorker


def build_indicator(cfg: AgentConfig, simulated_target: Decimal | None = None):
    kind = cfg.indicator.get("type", "simulated")
    parser = FrameParser(cfg.indicator.get("frame_pattern") or FrameParser().pattern)
    if kind == "serial":
        return SerialIndicator(cfg.indicator["port"], int(cfg.indicator.get("baudrate", 9600)), parser)
    if kind == "tcp":
        return TcpIndicator(cfg.indicator["host"], int(cfg.indicator["port"]), parser)
    return SimulatedIndicator(simulated_target or Decimal("25000"), cfg.resolution_kg, parser=parser)


def build_camera(cfg: AgentConfig):
    kind = cfg.camera.get("type", "simulated")
    if kind == "http":
        return HttpSnapshotCamera(cfg.camera["url"])
    if kind == "none":
        return None
    return SimulatedCamera()


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
    p = argparse.ArgumentParser(prog="ricemill-agent")
    p.add_argument("--config", default="agent.toml")
    sub = p.add_subparsers(dest="cmd", required=True)
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
    args = p.parse_args(argv)

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
        stats = LiveWeightStreamer(samples, api, cfg.scale_id, cfg.resolution_kg).run(args.count)
        print(json.dumps({"readings": stats.readings, "sent": stats.sent, "dropped": stats.dropped, "last_error": stats.last_error}))
        return 0
    if args.cmd == "manual-open":
        build_gate(cfg, store, api).manual_open(args.side, args.reason, args.entry)
        print("barrier opened manually; the event is queued for sync")
        return 0
    return 1


if __name__ == "__main__":
    sys.exit(main())
