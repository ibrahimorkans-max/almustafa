"""Agent configuration (TOML). Secrets may come from environment variables instead of the file:
RICEMILL_AGENT_PASSWORD, RICEMILL_AGENT_SIGNING_SECRET, RICEMILL_AGENT_STORE_KEY.
Keep the config file readable only by the service account running the agent.
"""

from __future__ import annotations

import os
import tomllib
from dataclasses import dataclass
from decimal import Decimal
from pathlib import Path


@dataclass(frozen=True)
class AgentConfig:
    server_url: str
    username: str
    password: str
    device_uid: str
    signing_secret: str
    store_path: Path
    store_key: bytes
    scale_id: str | None
    resolution_kg: Decimal
    min_stable_samples: int
    indicator: dict
    camera: dict

    @staticmethod
    def load(path: Path | str) -> "AgentConfig":
        data = tomllib.loads(Path(path).read_text(encoding="utf-8"))
        auth, store, scale = data.get("auth", {}), data.get("store", {}), data.get("scale", {})

        def secret(value: str | None, env: str) -> str:
            resolved = os.environ.get(env) or value
            if not resolved:
                raise ValueError(f"Missing secret: set {env} or the config value.")
            return resolved

        return AgentConfig(
            server_url=data["server"]["url"],
            username=auth["username"],
            password=secret(auth.get("password"), "RICEMILL_AGENT_PASSWORD"),
            device_uid=auth["device_uid"],
            signing_secret=secret(auth.get("signing_secret"), "RICEMILL_AGENT_SIGNING_SECRET"),
            store_path=Path(store.get("path", "agent-store.sqlite3")),
            store_key=secret(store.get("key"), "RICEMILL_AGENT_STORE_KEY").encode(),
            scale_id=scale.get("id"),
            resolution_kg=Decimal(str(scale.get("resolution_kg", "10"))),
            min_stable_samples=int(scale.get("min_stable_samples", 6)),
            indicator=data.get("indicator", {"type": "simulated"}),
            camera=data.get("camera", {"type": "simulated"}),
        )
