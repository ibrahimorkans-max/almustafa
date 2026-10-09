"""Upload photos, push queued events in order, pull the feed - same rules as the PWA engine.

An event leaves the outbox only on a final answer from the server. `retry` (e.g. the vehicle
was registered on another device that has not synced yet) and `pending_online` stay queued.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field

from .api import ApiClient, ApiError, OfflineError, stop_reason
from .queue import LocalStore

log = logging.getLogger(__name__)

FINAL = {"applied", "conflict_client_won", "conflict_server_won", "needs_review", "rejected"}


@dataclass
class SyncReport:
    state: str = "online"
    uploaded_files: int = 0
    pushed: int = 0
    kept: int = 0
    pulled: int = 0
    problems: list[str] = field(default_factory=list)


class SyncWorker:
    def __init__(self, store: LocalStore, api: ApiClient) -> None:
        self.store = store
        self.api = api

    def run(self) -> SyncReport:
        report = SyncReport()
        try:
            boot = self.api.bootstrap()
            self.store.set_meta("max_offline_hours", str(boot.get("max_offline_hours", 72)))
            self._upload_files(report)
            self._push(report, int(boot.get("max_push_events", 500)))
            self._pull(report)
            self.store.set_meta("stopped", "")
        except OfflineError as e:
            report.state = "offline"
            report.problems.append(str(e))
        except ApiError as e:
            reason = stop_reason(e)
            if reason is not None:
                # Lockdown / maintenance / access withdrawn: keep every queued reading for later.
                self.store.set_meta("stopped", reason)
                report.state = "locked" if reason == "system_locked" else reason
                report.problems.append(str(e.body.get("message") or reason))
            elif e.status == 403 and e.body.get("error") == "wipe_required":
                self.store.wipe()
                try:
                    self.api.wipe_ack()
                except (ApiError, OfflineError):
                    pass
                report.state = "wiped"
            else:
                report.state = "error"
                report.problems.append(str(e))
        return report

    def _upload_files(self, report: SyncReport) -> None:
        for sha, data in self.store.files_to_upload():
            mime = "image/png" if data[:8] == b"\x89PNG\r\n\x1a\n" else "image/jpeg"
            result = self.api.upload(data, mime)
            if result.get("sha256") != sha:
                raise ApiError(500, {"error": "file_hash_mismatch"})
            self.store.mark_uploaded(sha)
            report.uploaded_files += 1

    def _push(self, report: SyncReport, batch: int) -> None:
        pending = self.store.pending(batch)
        if not pending:
            return
        response = self.api.push([e.body for e in pending])
        for result in response.get("results", []):
            status = result.get("status")
            if status in FINAL:
                self.store.remove(result["event_id"])
                report.pushed += 1
                if status in {"rejected", "needs_review", "conflict_server_won"}:
                    report.problems.append(f"{result['event_id']}: {status} ({result.get('reason')})")
            else:
                self.store.mark_attempt(result["event_id"], str(status))
                report.kept += 1

    def _pull(self, report: SyncReport) -> None:
        cursor = int(self.store.get_meta("cursor", "0") or 0)
        while True:
            page = self.api.pull(cursor)
            for change in page.get("changes", []):
                if change["type"] not in {"gate_entry", "sauda", "vehicle"}:
                    continue
                if change["op"] == "archive":
                    self.store.drop_feed(change["type"], change["id"])
                else:
                    self.store.put_feed(change["type"], change["id"], change["data"])
                report.pulled += 1
            cursor = int(page.get("next_cursor", cursor))
            self.store.set_meta("cursor", str(cursor))
            if not page.get("has_more"):
                return
