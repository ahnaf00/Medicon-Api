"""Settings read from the environment (``.env`` next to agent.py)."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

ROOM_PREFIX = "appointment-"

# Audio format sent to the API (and on to Gemini): 16 kHz mono, lossless FLAC.
SAMPLE_RATE = 16_000
NUM_CHANNELS = 1
# AudioStream delivers frames of this size; small enough that a consent
# withdrawal stops the write within a tenth of a second.
FRAME_SIZE_MS = 100
# A new file is started after this much audio (about 3.6 MB of FLAC at 16 kHz).
CHUNK_SECONDS = 5 * 60


class ConfigError(RuntimeError):
    """A required setting is missing or invalid."""


@dataclass(frozen=True)
class Settings:
    livekit_url: str
    livekit_api_key: str
    livekit_api_secret: str
    api_base_url: str
    transcriber_secret: str
    storage_dir: Path
    chunk_seconds: int = CHUNK_SECONDS

    @classmethod
    def from_env(cls, base_dir: Path) -> "Settings":
        def required(name: str) -> str:
            value = os.environ.get(name, "").strip()
            if not value:
                raise ConfigError(f"{name} is not set (see .env.example).")
            return value

        secret = required("TRANSCRIBER_SECRET")
        if len(secret) < 32:
            raise ConfigError("TRANSCRIBER_SECRET must be at least 32 characters.")

        storage = Path(os.environ.get("TRANSCRIBER_STORAGE_DIR", "").strip() or base_dir / "storage")

        # Only for testing rotation without waiting five minutes.
        chunk_seconds = int(os.environ.get("TRANSCRIBER_CHUNK_SECONDS", "").strip() or CHUNK_SECONDS)
        if not 1 <= chunk_seconds <= CHUNK_SECONDS:
            raise ConfigError(f"TRANSCRIBER_CHUNK_SECONDS must be between 1 and {CHUNK_SECONDS}.")

        return cls(
            livekit_url=required("LIVEKIT_URL"),
            livekit_api_key=required("LIVEKIT_API_KEY"),
            livekit_api_secret=required("LIVEKIT_API_SECRET"),
            api_base_url=required("API_BASE_URL").rstrip("/"),
            transcriber_secret=secret,
            storage_dir=storage,
            chunk_seconds=chunk_seconds,
        )
