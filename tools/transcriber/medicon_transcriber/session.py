"""Per-room recording logic.

Audio is written only while the recording condition holds: the doctor and the
patient are both in the room and both have ``consent=true``. Those attributes
are set by the Laravel API (tokens + RoomService), never by the phones, so a
client cannot fake consent. The moment the condition stops holding every open
chunk is closed and audio arriving afterwards is dropped.
"""

from __future__ import annotations

import asyncio
import logging
import time
from collections.abc import Callable, Iterable, Mapping
from dataclasses import dataclass
from pathlib import Path

from livekit import rtc

from .chunks import ChunkInfo, ChunkWriter
from .config import FRAME_SIZE_MS, NUM_CHANNELS, SAMPLE_RATE

logger = logging.getLogger("medicon.transcriber.session")

ROLES = ("doctor", "patient")


@dataclass(frozen=True)
class Speaker:
    identity: str
    role: str
    consent: bool


def speaker_of(kind: int, identity: str, attributes: Mapping[str, str]) -> Speaker | None:
    """The doctor or patient this participant is, or None (agents, unknown roles)."""
    if kind != rtc.ParticipantKind.PARTICIPANT_KIND_STANDARD:
        return None
    role = attributes.get("role")
    if role not in ROLES:
        return None
    return Speaker(identity=identity, role=role, consent=attributes.get("consent") == "true")


def recording_allowed(speakers: Iterable[Speaker]) -> bool:
    """Both a doctor and a patient are present, and every one of them consents."""
    present = list(speakers)
    roles = {s.role for s in present}
    return set(ROLES) <= roles and all(s.consent for s in present)


class SpeakerRecorder:
    """Records one participant's microphone track into rotating chunks."""

    def __init__(self, room: str, room_dir: Path, identity: str, track: rtc.Track,
                 clock_ms: Callable[[], int], on_chunk: Callable[[ChunkInfo], None],
                 on_state: Callable[[], None], chunk_seconds: int) -> None:
        self.identity = identity
        self.track = track
        self._chunk_seconds = chunk_seconds
        self._room = room
        self._room_dir = room_dir
        self._clock_ms = clock_ms
        self._on_chunk = on_chunk
        self._on_state = on_state
        self._writer: ChunkWriter | None = None
        self._task: asyncio.Task[None] | None = None

    @property
    def writing(self) -> bool:
        return self._writer is not None and self._writer.is_open

    def start(self) -> None:
        self._task = asyncio.create_task(self._run(), name=f"record-{self.identity}")

    async def stop(self) -> None:
        """Stop reading audio and close the open chunk (if any)."""
        if self._task is not None:
            self._task.cancel()
            await asyncio.gather(self._task, return_exceptions=True)
            self._task = None
        self._close_chunk()

    async def _run(self) -> None:
        stream = rtc.AudioStream.from_track(
            track=self.track, sample_rate=SAMPLE_RATE, num_channels=NUM_CHANNELS,
            frame_size_ms=FRAME_SIZE_MS,
        )
        try:
            async for event in stream:
                frame = event.frame
                if self._writer is None:
                    # The frame just arrived, so its first sample began one frame earlier.
                    started = max(0, self._clock_ms() - int(frame.duration * 1000))
                    self._writer = ChunkWriter(
                        self._room, self._room_dir, self.identity, started, self._chunk_seconds
                    )
                    logger.info("chunk opened: %s", self._writer.path.name)
                    self._on_state()
                self._writer.write(frame.data)
                if self._writer.is_full:
                    # Rotation: the next frame opens the next chunk straight away, so the
                    # "recording" state does not change (no badge flicker).
                    self._close_chunk(notify=False)
        finally:
            await stream.aclose()

    def _close_chunk(self, notify: bool = True) -> None:
        writer, self._writer = self._writer, None
        if writer is None:
            return
        chunk = writer.close()
        if chunk is not None:
            logger.info("chunk closed: %s (%d ms)", chunk.path.name, chunk.duration_ms)
            self._on_chunk(chunk)
        if notify:
            self._on_state()


class RoomTranscriber:
    """Keeps recording in line with consent for one ``appointment-*`` room."""

    def __init__(self, room: rtc.Room, storage_dir: Path, on_chunk: Callable[[ChunkInfo], None],
                 chunk_seconds: int) -> None:
        self._room = room
        self._chunk_seconds = chunk_seconds
        self._room_dir = storage_dir / room.name
        self._on_chunk = on_chunk
        self._joined = time.monotonic()
        self._recorders: dict[str, SpeakerRecorder] = {}
        self._mic_tracks: dict[str, rtc.Track] = {}
        self._lock = asyncio.Lock()
        self._publish_lock = asyncio.Lock()
        # The agent joins with recording=false (set in agent.py's accept()).
        self._published_recording: bool | None = False
        self._closed = False

    def clock_ms(self) -> int:
        """Milliseconds since the agent joined the room."""
        return int((time.monotonic() - self._joined) * 1000)

    def attach(self) -> None:
        room = self._room
        room.on("participant_connected", lambda *_: self._schedule())
        room.on("participant_disconnected", self._on_participant_disconnected)
        room.on("participant_attributes_changed", lambda *_: self._schedule())
        room.on("track_subscribed", self._on_track_subscribed)
        room.on("track_unsubscribed", self._on_track_gone)
        room.on("track_muted", self._on_muted)
        room.on("track_unmuted", lambda *_: self._schedule())
        # Tracks already subscribed before attach() (e.g. participants present at join).
        for participant in room.remote_participants.values():
            for pub in participant.track_publications.values():
                if pub.track is not None and pub.source == rtc.TrackSource.SOURCE_MICROPHONE:
                    self._mic_tracks[participant.identity] = pub.track
        self._schedule()

    async def close(self) -> None:
        """Room is going away: close every chunk and mark recording off."""
        async with self._lock:
            self._closed = True
            await self._stop_all()
        await self._publish_recording()

    # --- events ---------------------------------------------------------------

    def _on_track_subscribed(self, track: rtc.Track, pub: rtc.RemoteTrackPublication,
                             participant: rtc.RemoteParticipant) -> None:
        if pub.source != rtc.TrackSource.SOURCE_MICROPHONE:
            return
        previous = self._mic_tracks.get(participant.identity)
        self._mic_tracks[participant.identity] = track
        if previous is not None and previous is not track:
            # Republished after a reconnect: finish the old chunk, start a new one.
            self._schedule(restart=participant.identity)
        else:
            self._schedule()

    def _on_track_gone(self, track: rtc.Track, pub: rtc.RemoteTrackPublication,
                       participant: rtc.RemoteParticipant) -> None:
        if self._mic_tracks.get(participant.identity) is track:
            del self._mic_tracks[participant.identity]
        self._schedule(restart=participant.identity)

    def _on_muted(self, participant: rtc.Participant, pub: rtc.TrackPublication) -> None:
        # No audio flows while muted; closing here keeps every chunk's start offset exact.
        if pub.source == rtc.TrackSource.SOURCE_MICROPHONE:
            self._schedule(restart=participant.identity)

    def _on_participant_disconnected(self, participant: rtc.RemoteParticipant) -> None:
        self._mic_tracks.pop(participant.identity, None)
        self._schedule()

    # --- reconciliation ---------------------------------------------------------

    def _schedule(self, restart: str | None = None) -> None:
        asyncio.create_task(self._reconcile(restart), name="reconcile-recording")

    async def _reconcile(self, restart: str | None = None) -> None:
        async with self._lock:
            if self._closed:
                return
            if restart and restart in self._recorders:
                await self._recorders.pop(restart).stop()

            speakers = [
                s for p in self._room.remote_participants.values()
                if (s := speaker_of(p.kind, p.identity, p.attributes)) is not None
            ]
            if not recording_allowed(speakers):
                if self._recorders:
                    logger.info("recording condition no longer holds; closing chunks")
                await self._stop_all()
                return

            for speaker in speakers:
                track = self._mic_tracks.get(speaker.identity)
                participant = self._room.remote_participants.get(speaker.identity)
                muted = participant is not None and any(
                    pub.source == rtc.TrackSource.SOURCE_MICROPHONE and pub.muted
                    for pub in participant.track_publications.values()
                )
                if track is None or muted or speaker.identity in self._recorders:
                    continue
                recorder = SpeakerRecorder(
                    self._room.name, self._room_dir, speaker.identity, track,
                    self.clock_ms, self._on_chunk, self._on_writing_changed, self._chunk_seconds,
                )
                self._recorders[speaker.identity] = recorder
                recorder.start()
                logger.info("recording %s (%s)", speaker.identity, speaker.role)

            # Recorders whose speaker left are stopped too.
            for identity in [i for i in self._recorders if i not in {s.identity for s in speakers}]:
                await self._recorders.pop(identity).stop()

    async def _stop_all(self) -> None:
        recorders, self._recorders = list(self._recorders.values()), {}
        for recorder in recorders:
            await recorder.stop()

    # --- the agent's own "recording" attribute ------------------------------------

    @property
    def writing(self) -> bool:
        return not self._closed and any(r.writing for r in self._recorders.values())

    def _on_writing_changed(self) -> None:
        asyncio.create_task(self._publish_recording(), name="publish-recording")

    async def _publish_recording(self) -> None:
        """The only source of the apps' "● Recording" badge (rule 3: never simulate).

        Reads the state when it runs (not when it was scheduled) and runs one at a
        time, so the last update always reflects what is actually being written.
        """
        async with self._publish_lock:
            writing = self.writing
            if writing == self._published_recording:
                return
            if not self._room.isconnected():
                # The room is gone (call ended); nobody is left to see the badge, and
                # waiting on the dead connection would delay the final uploads.
                return
            try:
                await self._room.local_participant.set_attributes(
                    {"recording": "true" if writing else "false"}
                )
                self._published_recording = writing
                logger.info("recording attribute -> %s", writing)
            except Exception as e:  # the room may already be gone at shutdown
                logger.debug("could not publish recording=%s: %s", writing, e)
