"""Request signing for the gate agent (mirrors App\\Http\\Middleware\\VerifyAgentSignature).

    X-Timestamp: unix seconds
    X-Nonce:     random, single use
    X-Signature: hex HMAC-SHA256(secret, "{ts}\\n{nonce}\\n{METHOD}\\n{path?query}\\n{sha256(body)}")
"""

from __future__ import annotations

import hashlib
import hmac
import time
import uuid


def canonical(timestamp: str, nonce: str, method: str, path_with_query: str, body: bytes) -> str:
    return "\n".join([timestamp, nonce, method.upper(), path_with_query, hashlib.sha256(body).hexdigest()])


def sign(secret: str, method: str, path_with_query: str, body: bytes, timestamp: int | None = None, nonce: str | None = None) -> dict[str, str]:
    ts = str(int(time.time()) if timestamp is None else timestamp)
    nonce = nonce or str(uuid.uuid4())
    signature = hmac.new(secret.encode(), canonical(ts, nonce, method, path_with_query, body).encode(), hashlib.sha256).hexdigest()
    return {"X-Timestamp": ts, "X-Nonce": nonce, "X-Signature": signature}
