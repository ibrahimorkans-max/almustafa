"""Gate operations on the agent: record arrivals and capture weighments, offline-first.

A capture waits for a stable reading, takes a camera photo, stores both in the encrypted local
store and queues a `weighment.captured` event. Nothing needs the network at that moment.
"""

from __future__ import annotations

import hashlib
import uuid
from dataclasses import dataclass
from decimal import Decimal

from .drivers.camera import Camera
from .drivers.indicator import WeightIndicator
from .queue import LocalStore
from .stability import StableReading, StableWeightDetector


class NoStableWeight(Exception):
    """The platform never settled (vehicle moving, wind, someone stepping on)."""


@dataclass(frozen=True)
class Capture:
    weighment_id: str
    gate_entry_id: str
    kind: str
    reading: StableReading
    photo_sha256: str | None


class GateAgent:
    def __init__(self, store: LocalStore, indicator: WeightIndicator, camera: Camera | None, scale_id: str | None,
                 resolution_kg: Decimal, min_samples: int = 6, max_frames: int = 400) -> None:
        self.store = store
        self.indicator = indicator
        self.camera = camera
        self.scale_id = scale_id
        self.detector = StableWeightDetector(resolution_kg, min_samples)
        self.max_frames = max_frames

    def arrive(self, registration_no: str, sauda_id: str | None = None, bag_count: int | None = None) -> str:
        """Records a vehicle arrival with a client-generated id; returns that id."""
        entry_id = str(uuid.uuid4())
        payload = {"registration_no": registration_no, "direction": "inbound", "purpose": "paddy_purchase"}
        if sauda_id:
            payload["sauda_id"] = sauda_id
        if bag_count is not None:
            payload["bag_count"] = bag_count
        self.store.enqueue("gate_entry.arrived", entry_id, payload)
        self.store.put_feed("gate_entry", entry_id, {"id": entry_id, **payload, "status": "arrived", "local": True})
        return entry_id

    def capture(self, gate_entry_id: str, kind: str) -> Capture:
        if kind not in ("gross", "tare"):
            raise ValueError("kind must be gross or tare")

        self.detector.reset()
        reading: StableReading | None = None
        for count, (weight, at_ms) in enumerate(self.indicator.samples(), start=1):
            reading = self.detector.feed(weight, at_ms)
            if reading is not None or count >= self.max_frames:
                break
        if reading is None:
            raise NoStableWeight("No stable weight - ask the driver to stop the vehicle fully on the platform.")

        photo_sha = None
        if self.camera is not None:
            try:
                photo = self.camera.capture()
            except Exception:  # noqa: BLE001 - a camera failure must not block weighing; the server flags "no_photo"
                photo = None
            if photo:
                photo_sha = hashlib.sha256(photo).hexdigest()
                self.store.put_file(photo_sha, photo)

        weighment_id = str(uuid.uuid4())
        payload = {
            "gate_entry_id": gate_entry_id,
            "kind": kind,
            "weight_kg": f"{reading.weight_kg:.3f}",
            "readings": reading.evidence(),
        }
        if self.scale_id:
            payload["scale_id"] = self.scale_id
        if photo_sha:
            payload["photo_sha256"] = photo_sha
        self.store.enqueue("weighment.captured", weighment_id, payload)
        return Capture(weighment_id, gate_entry_id, kind, reading, photo_sha)
