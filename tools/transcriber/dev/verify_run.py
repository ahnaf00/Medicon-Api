"""Check a simulate_call.py run against what the stub API received.

    python dev/verify_run.py --out dev-out --room appointment-99001

Prints every uploaded chunk (speaker, offset, duration, dominant frequency) and
the time windows covered per speaker, so speaker attribution and consent gaps
can be checked. Development and testing only.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import numpy as np
import soundfile as sf

EXPECTED_HZ = {"user-9001": 440, "user-9002": 660}


def dominant_hz(path: Path) -> float:
    data, rate = sf.read(path, dtype="float32")
    spectrum = np.abs(np.fft.rfft(data))
    return float(np.fft.rfftfreq(len(data), 1 / rate)[int(np.argmax(spectrum))])


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--out", type=Path, default=Path("dev-out"))
    p.add_argument("--room", default="appointment-99001")
    args = p.parse_args()

    requests = [json.loads(line) for line in (args.out / "stub" / "requests.jsonl").read_text().splitlines()]
    chunks = [r for r in requests if r["room"] == args.room and r["action"] == "chunks"]
    completes = [r for r in requests if r["room"] == args.room and r["action"] == "complete"]

    problems = []
    print(f"{'speaker':10} {'start_ms':>9} {'dur_ms':>7} {'end_ms':>7} {'Hz':>6}  file")
    for c in sorted(chunks, key=lambda c: (c["identity"], int(c["started_at_ms"]))):
        start, dur = int(c["started_at_ms"]), int(c["duration_ms"])
        f = args.out / "stub" / args.room / c["filename"]
        info = sf.info(f)
        hz = dominant_hz(f)
        print(f"{c['identity']:10} {start:9d} {dur:7d} {start + dur:7d} {hz:6.0f}  {c['filename']}")
        if info.samplerate != 16_000 or info.channels != 1 or info.format != "FLAC":
            problems.append(f"{c['filename']}: not 16 kHz mono FLAC")
        if abs(info.frames * 1000 // 16_000 - dur) > 1:
            problems.append(f"{c['filename']}: duration_ms {dur} != file length")
        if abs(hz - EXPECTED_HZ.get(c["identity"], hz)) > 5:
            problems.append(f"{c['filename']}: {hz:.0f} Hz belongs to the other speaker")

    print(f"\n/complete calls: {len(completes)} {completes}")
    leftover = list((args.out / "agent-storage" / args.room).glob("*.flac")) if (args.out / "agent-storage" / args.room).exists() else []
    print(f"files left in agent storage: {len(leftover)}")
    print("\nproblems:", problems or "none")


if __name__ == "__main__":
    main()
