"""One speaker's audio chunk: a 16 kHz mono FLAC file on local disk."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import numpy as np
import soundfile as sf

from .config import CHUNK_SECONDS, NUM_CHANNELS, SAMPLE_RATE


@dataclass(frozen=True)
class ChunkInfo:
    """A closed chunk, ready to upload."""

    room: str
    identity: str
    path: Path
    #: Offset of the chunk's first sample from when the agent joined the room.
    started_at_ms: int
    duration_ms: int


class ChunkWriter:
    """Writes PCM16 frames to ``{room_dir}/{identity}-{started_at_ms}.flac``.

    The duration comes from the number of samples written, not wall-clock time,
    so it is exact even if frames arrive late.
    """

    def __init__(self, room: str, room_dir: Path, identity: str, started_at_ms: int,
                 max_seconds: int = CHUNK_SECONDS) -> None:
        self.room = room
        self.identity = identity
        self.started_at_ms = started_at_ms
        self.max_samples = max_seconds * SAMPLE_RATE
        self.samples_written = 0

        room_dir.mkdir(parents=True, exist_ok=True)
        self.path = room_dir / f"{identity}-{started_at_ms}.flac"
        self._file: sf.SoundFile | None = sf.SoundFile(
            self.path, mode="w", samplerate=SAMPLE_RATE, channels=NUM_CHANNELS,
            format="FLAC", subtype="PCM_16",
        )

    @property
    def is_open(self) -> bool:
        return self._file is not None

    @property
    def is_full(self) -> bool:
        return self.samples_written >= self.max_samples

    def write(self, pcm16: bytes | memoryview) -> None:
        if self._file is None:
            raise RuntimeError("chunk is closed")
        samples = np.frombuffer(pcm16, dtype=np.int16)
        if samples.size:
            self._file.write(samples)
            self.samples_written += samples.size // NUM_CHANNELS

    def close(self) -> ChunkInfo | None:
        """Close the file. Returns None (and deletes it) if no audio was written."""
        if self._file is None:
            return None
        self._file.close()
        self._file = None

        if self.samples_written == 0:
            self.path.unlink(missing_ok=True)
            return None

        return ChunkInfo(
            room=self.room,
            identity=self.identity,
            path=self.path,
            started_at_ms=self.started_at_ms,
            duration_ms=self.samples_written * 1000 // SAMPLE_RATE,
        )
