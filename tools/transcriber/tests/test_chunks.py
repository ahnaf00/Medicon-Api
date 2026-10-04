import numpy as np
import soundfile as sf

from medicon_transcriber.chunks import ChunkWriter


def tone(seconds: float, hz: float = 440.0, rate: int = 16_000) -> bytes:
    t = np.arange(int(seconds * rate)) / rate
    return (np.sin(2 * np.pi * hz * t) * 8000).astype(np.int16).tobytes()


def test_writes_a_playable_16k_mono_flac(tmp_path):
    writer = ChunkWriter("appointment-12", tmp_path / "appointment-12", "user-1", started_at_ms=4321)
    for _ in range(15):  # 15 x 100 ms frames
        writer.write(tone(0.1))
    chunk = writer.close()

    assert chunk.path == tmp_path / "appointment-12" / "user-1-4321.flac"
    assert chunk.started_at_ms == 4321
    assert chunk.duration_ms == 1500
    data, rate = sf.read(chunk.path, dtype="int16")
    info = sf.info(chunk.path)
    assert rate == 16_000 and info.channels == 1 and info.format == "FLAC"
    assert len(data) == 24_000
    # Lossless: what was written is exactly what is read back.
    assert np.array_equal(data, np.frombuffer(b"".join(tone(0.1) for _ in range(15)), dtype=np.int16))


def test_reports_full_after_max_duration(tmp_path):
    writer = ChunkWriter("r", tmp_path, "user-1", 0, max_seconds=1)
    writer.write(tone(0.9))
    assert not writer.is_full
    writer.write(tone(0.1))
    assert writer.is_full
    writer.close()


def test_a_chunk_with_no_audio_leaves_no_file(tmp_path):
    writer = ChunkWriter("r", tmp_path, "user-1", 0)
    assert writer.close() is None
    assert list(tmp_path.iterdir()) == []


def test_close_is_idempotent(tmp_path):
    writer = ChunkWriter("r", tmp_path, "user-1", 0)
    writer.write(tone(0.1))
    assert writer.close() is not None
    assert writer.close() is None
    assert not writer.is_open
