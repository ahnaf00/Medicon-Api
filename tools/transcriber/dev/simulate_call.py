"""Simulate a doctor-patient call against the local LiveKit server.

    python dev/simulate_call.py --room appointment-99001 --scenario full

Two participants join with server-set ``role``/``consent`` attributes (as the
Laravel token endpoint does) and stream distinct tones: doctor 440 Hz, patient
660 Hz, so speaker attribution can be checked in the recorded files. Consent is
changed through RoomService, exactly as ``POST /call/consent`` does. Every action
and every change of the agent's ``recording`` attribute is written to --log.

Scenarios:
  full     both consent; patient withdraws at 6 s and re-grants at 10 s;
           doctor mutes 14-17 s; the room is deleted at 22 s (doctor ends the call)
  decline  the patient declines from the start; the room is deleted at 8 s
Development and testing only.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import time
from pathlib import Path

import numpy as np
from dotenv import load_dotenv
from livekit import api, rtc

RATE = 48_000
FRAME = RATE // 100  # 10 ms


class Timeline:
    def __init__(self, path: Path) -> None:
        self.t0 = time.monotonic()
        self.path = path
        self.events: list[dict] = []

    def add(self, kind: str, **data) -> None:
        event = {"t_ms": int((time.monotonic() - self.t0) * 1000), "event": kind, **data}
        self.events.append(event)
        print(json.dumps(event), flush=True)

    def save(self) -> None:
        self.path.write_text(json.dumps(self.events, indent=2))


def token(identity: str, room: str, role: str, consent: bool) -> str:
    return (
        api.AccessToken(os.environ["LIVEKIT_API_KEY"], os.environ["LIVEKIT_API_SECRET"])
        .with_identity(identity)
        .with_name(f"Sim {role}")
        .with_grants(api.VideoGrants(room_join=True, room=room, can_publish=True, can_subscribe=True))
        .with_attributes({"role": role, "consent": "true" if consent else "false"})
        .to_jwt()
    )


async def stream_tone(source: rtc.AudioSource, hz: float, stop: asyncio.Event) -> None:
    n = 0
    frame = rtc.AudioFrame.create(RATE, 1, FRAME)
    buf = np.frombuffer(frame.data, dtype=np.int16)
    next_at = time.monotonic()
    while not stop.is_set():
        t = (np.arange(FRAME) + n) / RATE
        np.copyto(buf, (np.sin(2 * np.pi * hz * t) * 6000).astype(np.int16))
        await source.capture_frame(frame)
        n += FRAME
        next_at += 0.01
        await asyncio.sleep(max(0.0, next_at - time.monotonic()))


async def join(url: str, room_name: str, identity: str, role: str, consent: bool, hz: float,
               stop: asyncio.Event) -> tuple[rtc.Room, rtc.LocalAudioTrack, asyncio.Task]:
    room = rtc.Room()
    await room.connect(url, token(identity, room_name, role, consent))
    source = rtc.AudioSource(RATE, 1)
    track = rtc.LocalAudioTrack.create_audio_track("microphone", source)
    await room.local_participant.publish_track(
        track, rtc.TrackPublishOptions(source=rtc.TrackSource.SOURCE_MICROPHONE)
    )
    task = asyncio.create_task(stream_tone(source, hz, stop))
    return room, track, task


async def main() -> None:
    load_dotenv(Path(__file__).resolve().parent.parent / ".env")
    p = argparse.ArgumentParser()
    p.add_argument("--room", default="appointment-99001")
    p.add_argument("--scenario", choices=["full", "decline"], default="full")
    p.add_argument("--log", type=Path, default=Path("dev-out/timeline.json"))
    args = p.parse_args()
    args.log.parent.mkdir(parents=True, exist_ok=True)

    url = os.environ["LIVEKIT_URL"]
    http_url = url.replace("ws://", "http://").replace("wss://", "https://")
    lk = api.LiveKitAPI(http_url, os.environ["LIVEKIT_API_KEY"], os.environ["LIVEKIT_API_SECRET"])
    tl = Timeline(args.log)
    stop = asyncio.Event()

    patient_consent = args.scenario == "full"
    doctor, doctor_track, t1 = await join(url, args.room, "user-9001", "doctor", True, 440, stop)
    patient, _, t2 = await join(url, args.room, "user-9002", "patient", patient_consent, 660, stop)
    tl.add("joined", doctor_consent=True, patient_consent=patient_consent)

    def watch(_changed, participant):
        if participant.kind == rtc.ParticipantKind.PARTICIPANT_KIND_AGENT:
            tl.add("agent_recording", value=participant.attributes.get("recording"))

    doctor.on("participant_attributes_changed", watch)
    for _ in range(100):  # wait up to 10 s for the agent to join
        agents = [x for x in doctor.remote_participants.values()
                  if x.kind == rtc.ParticipantKind.PARTICIPANT_KIND_AGENT]
        if agents:
            tl.add("agent_present", identity=agents[0].identity,
                   recording=agents[0].attributes.get("recording"))
            break
        await asyncio.sleep(0.1)
    else:
        tl.add("agent_missing")

    async def consent(identity: str, value: bool) -> None:
        await lk.room.update_participant(api.UpdateParticipantRequest(
            room=args.room, identity=identity, attributes={"consent": "true" if value else "false"}))
        tl.add("consent", identity=identity, value=value)

    async def at(seconds: float) -> None:
        await asyncio.sleep(max(0.0, seconds - (time.monotonic() - tl.t0)))

    if args.scenario == "full":
        await at(6); await consent("user-9002", False)
        await at(10); await consent("user-9002", True)
        await at(14); doctor_track.mute(); tl.add("doctor_muted")
        await at(17); doctor_track.unmute(); tl.add("doctor_unmuted")
        await at(22)
    else:
        await at(8)

    await lk.room.delete_room(api.DeleteRoomRequest(room=args.room))
    tl.add("room_deleted")
    stop.set()
    await asyncio.gather(t1, t2, return_exceptions=True)
    await doctor.disconnect()
    await patient.disconnect()
    await lk.aclose()
    tl.save()


if __name__ == "__main__":
    asyncio.run(main())
