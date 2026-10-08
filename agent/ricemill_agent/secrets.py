"""Windows DPAPI protection for the agent's secrets (login password, signing secret, store key).

`ricemill-agent protect` turns a secret into a value like ``dpapi:AQAAANCMnd8B...`` for agent.toml. Only
Windows on the same PC can turn it back (machine scope by default, or the current Windows account with
``--scope user``), so a copied config file or disk backup is useless elsewhere. Plain values and the
RICEMILL_AGENT_* environment variables keep working as before.
"""

from __future__ import annotations

import base64
import sys

PREFIX = "dpapi:"
# Fixed application entropy: another program calling DPAPI on this PC must also know it.
_ENTROPY = b"RiceMillERP-gate-agent-v1"
_CRYPTPROTECT_UI_FORBIDDEN = 0x01
_CRYPTPROTECT_LOCAL_MACHINE = 0x04


class SecretError(ValueError):
    pass


def available() -> bool:
    return sys.platform == "win32"


def protect(secret: str, scope: str = "machine") -> str:
    if scope not in ("machine", "user"):
        raise SecretError("scope must be 'machine' or 'user'")
    if not available():
        raise SecretError("DPAPI is only available on Windows; use the RICEMILL_AGENT_* environment variables instead.")
    flags = _CRYPTPROTECT_UI_FORBIDDEN | (_CRYPTPROTECT_LOCAL_MACHINE if scope == "machine" else 0)
    return PREFIX + base64.b64encode(_call("CryptProtectData", secret.encode("utf-8"), flags)).decode("ascii")


def reveal(value: str) -> str:
    """Return the plain secret for a config value (``dpapi:...`` is decrypted, anything else is returned as is)."""
    if not value.startswith(PREFIX):
        return value
    if not available():
        raise SecretError("This secret is DPAPI-protected and can only be read on the Windows PC that protected it.")
    try:
        blob = base64.b64decode(value[len(PREFIX):], validate=True)
    except ValueError as e:
        raise SecretError("The protected secret is damaged (not base64).") from e
    try:
        return _call("CryptUnprotectData", blob, _CRYPTPROTECT_UI_FORBIDDEN).decode("utf-8")
    except OSError as e:
        raise SecretError("This secret cannot be decrypted here: it was protected on another PC or Windows account, or it was changed.") from e


def _call(function: str, data: bytes, flags: int) -> bytes:
    import ctypes
    from ctypes import wintypes

    class Blob(ctypes.Structure):
        _fields_ = [("cbData", wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_char))]

    def blob_of(b: bytes) -> tuple[Blob, ctypes.Array]:
        buf = ctypes.create_string_buffer(b, len(b))
        return Blob(len(b), ctypes.cast(buf, ctypes.POINTER(ctypes.c_char))), buf

    crypt32 = ctypes.WinDLL("crypt32", use_last_error=True)
    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel32.LocalFree.argtypes = [ctypes.c_void_p]
    kernel32.LocalFree.restype = ctypes.c_void_p
    fn = getattr(crypt32, function)
    fn.restype = wintypes.BOOL

    data_in, _keep1 = blob_of(data)
    entropy, _keep2 = blob_of(_ENTROPY)
    data_out = Blob()
    if function == "CryptProtectData":
        ok = fn(ctypes.byref(data_in), "ricemill-agent", ctypes.byref(entropy), None, None, flags, ctypes.byref(data_out))
    else:
        ok = fn(ctypes.byref(data_in), None, ctypes.byref(entropy), None, None, flags, ctypes.byref(data_out))
    if not ok:
        raise ctypes.WinError(ctypes.get_last_error())
    try:
        return ctypes.string_at(data_out.pbData, data_out.cbData)
    finally:
        kernel32.LocalFree(ctypes.cast(data_out.pbData, ctypes.c_void_p))
