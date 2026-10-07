"""Encrypted local store: the outbox of events, photos waiting for upload, and the cached
server feed (expected vehicles, open gate entries). Everything at rest is encrypted with
Fernet (AES-128-CBC + HMAC-SHA256); the key comes from the agent's protected config.

The agent writes here FIRST and syncs later, so the weighbridge keeps working offline.
"""

from __future__ import annotations

import json
import sqlite3
import threading
import time
import uuid
from dataclasses import dataclass
from pathlib import Path

from cryptography.fernet import Fernet

SCHEMA = """
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS outbox (
    event_id TEXT PRIMARY KEY,
    client_seq INTEGER NOT NULL UNIQUE,
    created_at REAL NOT NULL,
    body BLOB NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_status TEXT
);
CREATE TABLE IF NOT EXISTS files (sha256 TEXT PRIMARY KEY, body BLOB NOT NULL, uploaded INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS feed (type TEXT NOT NULL, id TEXT NOT NULL, body BLOB NOT NULL, PRIMARY KEY (type, id));
"""


@dataclass(frozen=True)
class QueuedEvent:
    event_id: str
    client_seq: int
    body: dict
    attempts: int


class LocalStore:
    def __init__(self, path: Path | str, key: bytes) -> None:
        self._fernet = Fernet(key)
        self._lock = threading.Lock()
        self._db = sqlite3.connect(str(path), check_same_thread=False, isolation_level=None)
        self._db.execute("PRAGMA journal_mode=WAL")
        self._db.executescript(SCHEMA)

    @staticmethod
    def new_key() -> bytes:
        return Fernet.generate_key()

    def _enc(self, value: object) -> bytes:
        return self._fernet.encrypt(json.dumps(value, separators=(",", ":")).encode())

    def _dec(self, blob: bytes) -> object:
        return json.loads(self._fernet.decrypt(blob))

    # ------------------------------------------------------------------ meta

    def get_meta(self, key: str, default: str | None = None) -> str | None:
        row = self._db.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
        return row[0] if row else default

    def set_meta(self, key: str, value: str) -> None:
        self._db.execute("INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", (key, value))

    # ------------------------------------------------------------------ outbox

    def enqueue(self, event_type: str, record_id: str | None, payload: dict, occurred_at: str | None = None) -> QueuedEvent:
        with self._lock:
            seq = int(self.get_meta("client_seq", "0")) + 1
            self.set_meta("client_seq", str(seq))
            event = {
                "event_id": str(uuid.uuid4()),
                "client_seq": seq,
                "occurred_at": occurred_at or time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "type": event_type,
                "record_id": record_id,
                "payload": payload,
            }
            self._db.execute(
                "INSERT INTO outbox (event_id, client_seq, created_at, body) VALUES (?, ?, ?, ?)",
                (event["event_id"], seq, time.time(), self._enc(event)),
            )
            return QueuedEvent(event["event_id"], seq, event, 0)

    def pending(self, limit: int = 500) -> list[QueuedEvent]:
        rows = self._db.execute(
            "SELECT event_id, client_seq, body, attempts FROM outbox ORDER BY client_seq LIMIT ?", (limit,)
        ).fetchall()
        return [QueuedEvent(r[0], r[1], self._dec(r[2]), r[3]) for r in rows]  # type: ignore[arg-type]

    def remove(self, event_id: str) -> None:
        self._db.execute("DELETE FROM outbox WHERE event_id = ?", (event_id,))

    def mark_attempt(self, event_id: str, status: str) -> None:
        self._db.execute("UPDATE outbox SET attempts = attempts + 1, last_status = ? WHERE event_id = ?", (status, event_id))

    def unsynced_count(self) -> int:
        return int(self._db.execute("SELECT COUNT(*) FROM outbox").fetchone()[0])

    # ------------------------------------------------------------------ files

    def put_file(self, sha256: str, data: bytes) -> None:
        self._db.execute("INSERT OR IGNORE INTO files (sha256, body) VALUES (?, ?)", (sha256, self._fernet.encrypt(data)))

    def files_to_upload(self) -> list[tuple[str, bytes]]:
        rows = self._db.execute("SELECT sha256, body FROM files WHERE uploaded = 0").fetchall()
        return [(r[0], self._fernet.decrypt(r[1])) for r in rows]

    def mark_uploaded(self, sha256: str) -> None:
        self._db.execute("UPDATE files SET uploaded = 1 WHERE sha256 = ?", (sha256,))

    # ------------------------------------------------------------------ cached server feed

    def put_feed(self, change_type: str, record_id: str, data: dict) -> None:
        self._db.execute(
            "INSERT INTO feed (type, id, body) VALUES (?, ?, ?) ON CONFLICT(type, id) DO UPDATE SET body = excluded.body",
            (change_type, record_id, self._enc(data)),
        )

    def drop_feed(self, change_type: str, record_id: str) -> None:
        self._db.execute("DELETE FROM feed WHERE type = ? AND id = ?", (change_type, record_id))

    def feed(self, change_type: str) -> list[dict]:
        rows = self._db.execute("SELECT body FROM feed WHERE type = ?", (change_type,)).fetchall()
        return [self._dec(r[0]) for r in rows]  # type: ignore[misc]

    # ------------------------------------------------------------------ wipe

    def wipe(self) -> None:
        """Remote wipe: drop every row (and the tokens kept in meta)."""
        for table in ("outbox", "files", "feed", "meta"):
            self._db.execute(f"DELETE FROM {table}")  # noqa: S608 - fixed table names
        self._db.execute("VACUUM")
