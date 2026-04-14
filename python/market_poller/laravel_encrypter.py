"""Laravel-compatible encrypt/decrypt for cross-plane token sharing.

Reads and writes the same envelope format Laravel's `Illuminate\\Encryption\\Encrypter`
uses, so tokens stored by the Laravel side (via the Eloquent `'encrypted'`
cast) can be consumed + rotated by the Python execution plane.

Envelope shape (AES-256-CBC, default in Laravel 12):

    base64(
        json({
            "iv":    base64(16_bytes_random_iv),
            "value": base64(AES-256-CBC(key, iv, plaintext_pkcs7_padded)),
            "mac":   hex(HMAC-SHA256(key, iv_b64 || value_b64)),
            "tag":   ""        // populated only for AES-256-GCM; we reject those here
        })
    )

Two important Laravel quirks we mirror:

  1. APP_KEY has the form `base64:<32_bytes_b64>`. The `base64:` prefix
     is stripped before decode. `from_app_key()` handles both forms.

  2. Strings stored via the Eloquent `'encrypted'` cast are encrypted
     with `Crypt::encrypt($value, $serialize=false)` — i.e. the
     plaintext is NOT PHP-serialized before encryption. So our decrypt
     returns the raw string, no `serialize()` / `unserialize()` step.

     (The `Crypt::encrypt($value)` default, `$serialize=true`, wraps
      arbitrary PHP values in `serialize()`. The `'encrypted'` cast
      explicitly passes `false`. We only support the cast path.)

The MAC protects against bit-flipping attacks on the IV+ciphertext.
Laravel uses `hash_hmac('sha256', $iv.$value, $key)` on the base64
strings — same wire shape we verify against.

Only AES-256-CBC is supported. If Laravel is reconfigured to
AES-256-GCM, `tag` becomes populated and we raise a clear error
rather than silently producing wrong output.

Ships under `market_poller` because this is its first caller. If/when
a second Python-plane package needs the same, promote to `python/_common/`
alongside the other duplicated scaffolding.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os

from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes


class LaravelEncrypterError(Exception):
    """Raised on any envelope validation or cryptographic failure.

    Caller treats this as a security-boundary violation: whatever the
    downstream effect would have been, it does NOT happen. The Python
    poller logs + disables the affected row immediately."""


class LaravelEncrypter:
    """Encrypt / decrypt strings with the same wire format as Laravel
    12's `Illuminate\\Encryption\\Encrypter` in AES-256-CBC mode.

    Not thread-safe — create one instance per logical owner. Cheap to
    construct (just holds the key bytes)."""

    _KEY_BYTES = 32           # AES-256.
    _IV_BYTES = 16            # AES block size.
    _BLOCK = 16               # PKCS7 padding block size.

    def __init__(self, key: bytes) -> None:
        if len(key) != self._KEY_BYTES:
            raise LaravelEncrypterError(
                f"key must be {self._KEY_BYTES} bytes, got {len(key)}"
            )
        self._key = key

    @classmethod
    def from_app_key(cls, app_key: str) -> "LaravelEncrypter":
        """Parse Laravel's APP_KEY env value. Accepts both the
        `base64:xxx` form (how Laravel writes it) and a bare base64
        string. Raises LaravelEncrypterError on decode failure so an
        operator with a malformed APP_KEY sees the exact reason."""
        if not app_key:
            raise LaravelEncrypterError("APP_KEY is empty")
        prefix = "base64:"
        raw = app_key[len(prefix):] if app_key.startswith(prefix) else app_key
        try:
            key = base64.b64decode(raw, validate=True)
        except (ValueError, base64.binascii.Error) as exc:
            raise LaravelEncrypterError(f"APP_KEY base64 decode failed: {exc}") from exc
        return cls(key)

    # -- decrypt ----------------------------------------------------------

    def decrypt(self, payload: str) -> str:
        """Reverse `Crypt::encryptString($value)`. Returns the plaintext
        as a UTF-8 string. Raises LaravelEncrypterError on any failure
        — envelope malformed, MAC mismatch, padding wrong, UTF-8 decode
        failure."""
        envelope = self._parse_envelope(payload)

        if envelope.get("tag"):
            # AES-256-GCM envelopes carry the auth tag here. We don't
            # implement GCM — fail loudly so the operator can pin
            # Laravel to CBC or ask for GCM support.
            raise LaravelEncrypterError(
                "AES-256-GCM envelopes not supported (tag field populated); "
                "pin Laravel to AES-256-CBC or add GCM support here"
            )

        iv_b64 = envelope.get("iv")
        value_b64 = envelope.get("value")
        mac_hex = envelope.get("mac")
        if not iv_b64 or not value_b64 or not mac_hex:
            raise LaravelEncrypterError("envelope missing iv/value/mac field")

        expected_mac = hmac.new(
            self._key,
            (iv_b64 + value_b64).encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        if not hmac.compare_digest(expected_mac, mac_hex):
            raise LaravelEncrypterError("MAC verification failed")

        try:
            iv = base64.b64decode(iv_b64, validate=True)
            value = base64.b64decode(value_b64, validate=True)
        except (ValueError, base64.binascii.Error) as exc:
            raise LaravelEncrypterError(f"iv/value base64 decode failed: {exc}") from exc

        if len(iv) != self._IV_BYTES:
            raise LaravelEncrypterError(f"iv must be {self._IV_BYTES} bytes, got {len(iv)}")
        if len(value) == 0 or len(value) % self._BLOCK != 0:
            raise LaravelEncrypterError(
                f"ciphertext length {len(value)} not a multiple of {self._BLOCK}"
            )

        cipher = Cipher(algorithms.AES(self._key), modes.CBC(iv), backend=default_backend())
        decryptor = cipher.decryptor()
        padded = decryptor.update(value) + decryptor.finalize()

        plaintext_bytes = _pkcs7_unpad(padded, self._BLOCK)
        try:
            return plaintext_bytes.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise LaravelEncrypterError(f"plaintext UTF-8 decode failed: {exc}") from exc

    # -- encrypt ----------------------------------------------------------

    def encrypt(self, plaintext: str) -> str:
        """Produce a `Crypt::encryptString($value)`-compatible envelope.
        Used when persisting a rotated refresh token back into
        `eve_service_tokens` so the Laravel side continues to read it
        correctly."""
        utf8 = plaintext.encode("utf-8")
        padded = _pkcs7_pad(utf8, self._BLOCK)

        iv = os.urandom(self._IV_BYTES)
        cipher = Cipher(algorithms.AES(self._key), modes.CBC(iv), backend=default_backend())
        encryptor = cipher.encryptor()
        ciphertext = encryptor.update(padded) + encryptor.finalize()

        iv_b64 = base64.b64encode(iv).decode("ascii")
        value_b64 = base64.b64encode(ciphertext).decode("ascii")
        mac = hmac.new(
            self._key,
            (iv_b64 + value_b64).encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

        envelope = {
            "iv": iv_b64,
            "value": value_b64,
            "mac": mac,
            "tag": "",
        }
        # Laravel's PHP json_encode produces no whitespace; match it so
        # the MAC we just computed is stable across round-trips through
        # any subsequent Laravel re-encoding (not that we do that, but
        # the consistency makes accidental mismatches easier to spot).
        envelope_json = json.dumps(envelope, separators=(",", ":"))
        return base64.b64encode(envelope_json.encode("utf-8")).decode("ascii")

    # -- internals --------------------------------------------------------

    @staticmethod
    def _parse_envelope(payload: str) -> dict:
        if not payload:
            raise LaravelEncrypterError("empty payload")
        try:
            envelope_json = base64.b64decode(payload, validate=True)
        except (ValueError, base64.binascii.Error) as exc:
            raise LaravelEncrypterError(f"envelope base64 decode failed: {exc}") from exc
        try:
            envelope = json.loads(envelope_json)
        except json.JSONDecodeError as exc:
            raise LaravelEncrypterError(f"envelope JSON decode failed: {exc}") from exc
        if not isinstance(envelope, dict):
            raise LaravelEncrypterError(f"envelope is not an object: {type(envelope).__name__}")
        return envelope


def _pkcs7_pad(data: bytes, block: int) -> bytes:
    pad_len = block - (len(data) % block)
    return data + bytes([pad_len] * pad_len)


def _pkcs7_unpad(data: bytes, block: int) -> bytes:
    if len(data) == 0 or len(data) % block != 0:
        raise LaravelEncrypterError(f"padded length {len(data)} not a multiple of {block}")
    pad_len = data[-1]
    if pad_len < 1 or pad_len > block:
        raise LaravelEncrypterError(f"invalid PKCS7 padding byte: {pad_len}")
    # Constant-time-ish validation: every one of the last `pad_len` bytes
    # must equal pad_len. We don't sweat timing side channels on this
    # particular check — the MAC verification earlier already failed the
    # adversary's bit-flipping attempt on the ciphertext, so padding
    # oracles aren't a realistic concern here.
    if data[-pad_len:] != bytes([pad_len] * pad_len):
        raise LaravelEncrypterError("PKCS7 padding bytes don't match declared length")
    return data[:-pad_len]
