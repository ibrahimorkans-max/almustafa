"""Command line: `ricemill-agent --config agent.toml <command>`.

    status                       unsynced events, last cursor
    sync                         upload photos, push events, pull the feed
    arrive REG [--sauda ID]      record a vehicle arrival (works offline)
    capture ENTRY gross|tare     wait for a stable weight, photograph, queue
    simulate REG GROSS TARE      full inbound weighing with the simulated indicator
    new-store-key                print a fresh encryption key for [store].key
"""

from __future__ import annotations

import argparse
import json
import sys
from decimal import Decimal

from .api import ApiClient, Credentials
from .config import AgentConfig
from .drivers.camera import HttpSnapshotCamera, SimulatedCamera
from .drivers.indicator import FrameParser, SerialIndicator, SimulatedIndicator, TcpIndicator
from .gate import GateAgent
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


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="ricemill-agent")
    p.add_argument("--config", default="agent.toml")
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("status")
    sub.add_parser("sync")
    sub.add_parser("new-store-key")
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
    args = p.parse_args(argv)

    if args.cmd == "new-store-key":
        print(LocalStore.new_key().decode())
        return 0

    cfg = AgentConfig.load(args.config)
    store = LocalStore(cfg.store_path, cfg.store_key)
    api = ApiClient(cfg.server_url, Credentials(cfg.username, cfg.password, cfg.device_uid, cfg.signing_secret))

    if args.cmd == "status":
        print(json.dumps({"unsynced": store.unsynced_count(), "cursor": store.get_meta("cursor", "0")}))
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
    return 1


if __name__ == "__main__":
    sys.exit(main())
