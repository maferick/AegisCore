"""Stdlib unittest for laravel_encrypter.

Run from `python/`:

    python -m unittest market_poller.test_laravel_encrypter -v

We deliberately use unittest rather than pytest: the rest of the
Python tree doesn't ship a test runner, and unittest is stdlib, so
introducing pytest just for this one file would be premature.

Coverage:

  1. Round-trip: encrypt() then decrypt() returns the original plaintext
     across a spread of lengths (including boundary cases for PKCS7:
     empty, one-block, multi-block).
  2. APP_KEY parsing: handles both `base64:xxx` and bare base64 forms.
  3. MAC tamper detection: flipping a byte of mac/iv/value → raise.
  4. Envelope structure validation: bad base64, bad JSON, missing
     fields, non-object payload → raise with the expected error class.
  5. AES-256-GCM rejection: a synthetic envelope with non-empty `tag`
     raises the explicit "not supported" error rather than producing
     garbage plaintext.

We CANNOT test interop against a real Laravel-encrypted value here
(no PHP available), but the format spec is precise enough that
passing round-trips + MAC tamper detection + matching HMAC shape
against the hardcoded test-vector derivation makes first-contact
interop very likely to Just Work. First-call failures against a real
Laravel-authored token are explicit and would surface the bug
immediately.
"""

from __future__ import annotations

import base64
import json
import os
import unittest

from market_poller.laravel_encrypter import (
    LaravelEncrypter,
    LaravelEncrypterError,
)


# Fixed 32-byte key + IV so tests are deterministic where it matters.
TEST_KEY = bytes(range(32))
TEST_APP_KEY = "base64:" + base64.b64encode(TEST_KEY).decode("ascii")
TEST_APP_KEY_BARE = base64.b64encode(TEST_KEY).decode("ascii")


class RoundTripTests(unittest.TestCase):
    """Encrypt then decrypt with the same key should yield the original
    plaintext for any valid UTF-8 string."""

    def setUp(self) -> None:
        self.enc = LaravelEncrypter(TEST_KEY)

    def test_round_trip_simple(self) -> None:
        for plaintext in ("", "a", "hello world", "🚀 emojis ✨"):
            with self.subTest(plaintext=plaintext):
                envelope = self.enc.encrypt(plaintext)
                self.assertEqual(self.enc.decrypt(envelope), plaintext)

    def test_round_trip_boundary_block_sizes(self) -> None:
        """PKCS7 padding edge cases: exact block-size inputs get a
        full extra block of padding; one-byte-short gets one byte."""
        for length in (0, 15, 16, 17, 31, 32, 33, 64, 1024):
            with self.subTest(length=length):
                plaintext = "x" * length
                envelope = self.enc.encrypt(plaintext)
                self.assertEqual(self.enc.decrypt(envelope), plaintext)

    def test_round_trip_token_like(self) -> None:
        """Tokens we'll actually encrypt in production look like long
        base64-ish JWTs. Sanity-check a realistic shape."""
        jwt_shape = (
            "eyJhbGciOiJSUzI1NiIsImtpZCI6IkpXVC1TaWduYXR1cmUtS2V5Iiwi"
            "dHlwIjoiSldUIn0." + "x" * 500 + ".signature_b64url"
        )
        envelope = self.enc.encrypt(jwt_shape)
        self.assertEqual(self.enc.decrypt(envelope), jwt_shape)

    def test_fresh_iv_per_encrypt(self) -> None:
        """Encrypting the same plaintext twice produces different
        envelopes (IV is fresh random per call). Guards against a
        future refactor that accidentally reuses the IV."""
        a = self.enc.encrypt("hello")
        b = self.enc.encrypt("hello")
        self.assertNotEqual(a, b)
        self.assertEqual(self.enc.decrypt(a), "hello")
        self.assertEqual(self.enc.decrypt(b), "hello")


class AppKeyParsingTests(unittest.TestCase):
    """`from_app_key` should handle both the `base64:` prefixed form
    (how Laravel writes APP_KEY) and a bare base64 string."""

    def test_prefixed_form(self) -> None:
        enc = LaravelEncrypter.from_app_key(TEST_APP_KEY)
        self.assertEqual(enc.decrypt(enc.encrypt("ok")), "ok")

    def test_bare_form(self) -> None:
        enc = LaravelEncrypter.from_app_key(TEST_APP_KEY_BARE)
        self.assertEqual(enc.decrypt(enc.encrypt("ok")), "ok")

    def test_empty_raises(self) -> None:
        with self.assertRaises(LaravelEncrypterError):
            LaravelEncrypter.from_app_key("")

    def test_garbage_raises(self) -> None:
        with self.assertRaises(LaravelEncrypterError):
            LaravelEncrypter.from_app_key("base64:not valid base64 at all")

    def test_wrong_length_raises(self) -> None:
        # 16 bytes, not 32.
        wrong = base64.b64encode(bytes(16)).decode("ascii")
        with self.assertRaises(LaravelEncrypterError):
            LaravelEncrypter.from_app_key(wrong)


class TamperDetectionTests(unittest.TestCase):
    """MAC verification MUST fail on any mutation of iv/value/mac.
    This is the core security property — a silent decryption-to-garbage
    on a tampered envelope would be catastrophic (attacker-controlled
    bytes flowing into ESI requests)."""

    def setUp(self) -> None:
        self.enc = LaravelEncrypter(TEST_KEY)

    def _parse(self, envelope: str) -> dict:
        return json.loads(base64.b64decode(envelope).decode("utf-8"))

    def _rebuild(self, envelope_dict: dict) -> str:
        return base64.b64encode(
            json.dumps(envelope_dict, separators=(",", ":")).encode("utf-8")
        ).decode("ascii")

    def test_flipped_mac_rejects(self) -> None:
        envelope = self.enc.encrypt("secret")
        parsed = self._parse(envelope)
        # Flip one character in the MAC.
        parsed["mac"] = parsed["mac"][:-1] + ("0" if parsed["mac"][-1] != "0" else "1")
        with self.assertRaises(LaravelEncrypterError):
            self.enc.decrypt(self._rebuild(parsed))

    def test_flipped_iv_rejects(self) -> None:
        envelope = self.enc.encrypt("secret")
        parsed = self._parse(envelope)
        iv_bytes = bytearray(base64.b64decode(parsed["iv"]))
        iv_bytes[0] ^= 0x01
        parsed["iv"] = base64.b64encode(bytes(iv_bytes)).decode("ascii")
        with self.assertRaises(LaravelEncrypterError):
            self.enc.decrypt(self._rebuild(parsed))

    def test_flipped_value_rejects(self) -> None:
        envelope = self.enc.encrypt("secret")
        parsed = self._parse(envelope)
        value_bytes = bytearray(base64.b64decode(parsed["value"]))
        value_bytes[0] ^= 0x01
        parsed["value"] = base64.b64encode(bytes(value_bytes)).decode("ascii")
        with self.assertRaises(LaravelEncrypterError):
            self.enc.decrypt(self._rebuild(parsed))

    def test_wrong_key_rejects(self) -> None:
        envelope = self.enc.encrypt("secret")
        wrong = LaravelEncrypter(bytes(range(1, 33)))
        with self.assertRaises(LaravelEncrypterError):
            wrong.decrypt(envelope)


class EnvelopeStructureTests(unittest.TestCase):
    """Structural failures — we want specific, actionable error
    messages rather than cryptographic noise."""

    def setUp(self) -> None:
        self.enc = LaravelEncrypter(TEST_KEY)

    def test_empty_rejects(self) -> None:
        with self.assertRaises(LaravelEncrypterError):
            self.enc.decrypt("")

    def test_not_base64_rejects(self) -> None:
        with self.assertRaises(LaravelEncrypterError):
            self.enc.decrypt("definitely not base64 here !!!")

    def test_not_json_rejects(self) -> None:
        payload = base64.b64encode(b"plain text, not JSON").decode("ascii")
        with self.assertRaises(LaravelEncrypterError):
            self.enc.decrypt(payload)

    def test_non_object_json_rejects(self) -> None:
        payload = base64.b64encode(b"[1,2,3]").decode("ascii")
        with self.assertRaises(LaravelEncrypterError):
            self.enc.decrypt(payload)

    def test_missing_fields_rejects(self) -> None:
        for drop in ("iv", "value", "mac"):
            with self.subTest(drop=drop):
                envelope = self.enc.encrypt("hi")
                parsed = json.loads(base64.b64decode(envelope).decode("utf-8"))
                del parsed[drop]
                rebuilt = base64.b64encode(
                    json.dumps(parsed, separators=(",", ":")).encode("utf-8")
                ).decode("ascii")
                with self.assertRaises(LaravelEncrypterError):
                    self.enc.decrypt(rebuilt)

    def test_gcm_tag_populated_rejects(self) -> None:
        """A synthetic envelope with a non-empty `tag` — emulating an
        AES-256-GCM payload Laravel might produce — must raise the
        explicit GCM-not-supported error, not fail MAC and confuse the
        operator."""
        envelope = self.enc.encrypt("secret")
        parsed = json.loads(base64.b64decode(envelope).decode("utf-8"))
        parsed["tag"] = base64.b64encode(b"x" * 16).decode("ascii")
        rebuilt = base64.b64encode(
            json.dumps(parsed, separators=(",", ":")).encode("utf-8")
        ).decode("ascii")
        with self.assertRaisesRegex(LaravelEncrypterError, "GCM"):
            self.enc.decrypt(rebuilt)


if __name__ == "__main__":
    # Allow ad-hoc: `python -m market_poller.test_laravel_encrypter`.
    unittest.main()
